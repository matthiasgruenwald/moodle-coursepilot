<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * Die Ausfallbehandlung des Moodle-Zweigs von {@see context_area} (Issue
 * #540, ADR 0023 "an beiden Orten"): ein Ausfall beim Persistieren selbst -
 * nicht: Pfad-/Endungs-/Quotenpruefung, nicht: Konflikt - vermerkt einen
 * Ausstand, bevor der Fehler zurueckgeht.
 *
 * Ein echter Ausfall der Moodle-Dateiablage (Datenbank/Dateisystem) laesst
 * sich in einer Testumgebung nicht gefahrlos herbeifuehren - anders als beim
 * externen Ort gibt es dafuer keinen injizierbaren Fake-Transport. Dieser
 * Test greift deshalb ueber Reflection direkt auf die private, statische
 * Kernmethode zu ({@see context_area::persist_moodle_write()}/{@see context_area::persist_moodle_append()})
 * und uebergibt ihr einen Test-Doppelgaenger des {@see storage_port}-Vertrags
 * (dessen Typ die Methode ohnehin annimmt, nicht die konkrete
 * {@see private_files_storage_port}) - derselbe Vertrag, den auch
 * {@see private_files_storage_port_test} gegen den echten Adapter prueft.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(context_area::class)]
final class context_area_ausstand_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Ein Ausfall beim Persistieren (hier simuliert: ein beliebiger Fehler
     * des Adapters) vermerkt einen Ausstand, bevor der Fehler zurueckgeht -
     * nie roh durchgereicht (Issue #540 Abnahmekriterium 1+5).
     */
    public function test_write_records_ausstand_when_the_port_fails_to_persist(): void {
        $port = $this->failing_port(new \RuntimeException('Platte voll (Simuliert)'));

        try {
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_CREATE, 7);
            $this->fail('Der Ausfall haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
            $this->assertStringContainsString('plan.md', $e->getMessage());
        }

        $ausstaende = pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['pfad']);
        $entry = $ausstaende[0]['eintraege'][0];
        $this->assertSame(pending_write_translation::OP_CREATE, $entry['vorgang']);
        $this->assertSame(7, $entry['kursid']);
    }

    /**
     * Dieselbe Ausfallbehandlung greift beim Anhaengen.
     */
    public function test_append_records_ausstand_when_the_port_fails_to_persist(): void {
        $port = $this->failing_port(new \RuntimeException('Platte voll (Simuliert)'));

        try {
            $this->invoke_persist_append($port, 'journal.md', 'Zeile', pending_write_translation::OP_APPEND, 0);
            $this->fail('Der Ausfall haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = pending_write_notice::list_grouped();
        $this->assertSame(pending_write_translation::OP_APPEND, $ausstaende[0]['eintraege'][0]['vorgang']);
    }

    /**
     * Die Quotenpruefung des Bereichs bleibt ein Aufruffehler, kein
     * Ausstand (Issue #540 Abnahmekriterium 2, ADR 0023 Punkt 2) - auch wenn
     * sie erst beim Persistieren selbst durchschlaegt.
     */
    public function test_write_quota_exceeded_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new \moodle_exception('contextquotaexceeded', 'local_coursepilot', '', (object) [
            'available' => 0,
            'page' => '',
        ]));

        try {
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_CREATE, 0);
            $this->fail('Die Quotenpruefung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextquotaexceeded', $e->errorcode);
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Ein Pruefwert-Konflikt ({@see storage_conflict_exception}) zaehlt
     * weiterhin nicht als Ausstand (Issue #540 Abnahmekriterium 2).
     */
    public function test_write_conflict_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new storage_conflict_exception('plan.md'));

        try {
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_OVERWRITE, 0);
            $this->fail('Der Konflikt haette abgewiesen werden muessen.');
        } catch (storage_conflict_exception $e) {
            // Erwartet.
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * @param \Throwable $failure Wird von write()/append() des Doppelgaengers geworfen.
     * @return storage_port
     */
    private function failing_port(\Throwable $failure): storage_port {
        return new class($failure) implements storage_port {
            public function __construct(private readonly \Throwable $failure) {
            }

            public function read(storage_area $area, string $path): ?array {
                return null;
            }

            public function list(storage_area $area, string $path): array {
                return [];
            }

            public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
                throw $this->failure;
            }

            public function append(storage_area $area, string $path, string $content): array {
                throw $this->failure;
            }

            public function delete(storage_area $area, string $path): bool {
                return false;
            }
        };
    }

    /**
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array
     */
    private function invoke_persist_write(storage_port $port, string $path, string $content, string $operation, int $courseid): array {
        $method = new \ReflectionMethod(context_area::class, 'persist_moodle_write');
        $method->setAccessible(true);
        return $method->invoke(null, $port, $path, $content, $operation, $courseid);
    }

    /**
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array
     */
    private function invoke_persist_append(storage_port $port, string $path, string $content, string $operation, int $courseid): array {
        $method = new \ReflectionMethod(context_area::class, 'persist_moodle_append');
        $method->setAccessible(true);
        return $method->invoke(null, $port, $path, $content, $operation, $courseid);
    }
}
