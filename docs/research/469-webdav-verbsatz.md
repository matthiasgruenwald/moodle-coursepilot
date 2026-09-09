# Recherche: WebDAV-Verbsatz gegen die Anforderungen von Kontextbereich und Materialordner

Issue [#469](https://github.com/matthiasgruenwald/Kurspilot/issues/469), Karte [#467](https://github.com/matthiasgruenwald/Kurspilot/issues/467).

**Frage:** Kann `\webdav_client` (Moodle-Core, `lib/webdavlib.php`) alles, was Kontextbereich und Materialordner heute von ihrem Ablageort verlangen?

**Quellen:** ausschließlich Quelltext und Normtexte.

- Kurspilot: `Plugin/src/local_kurspilot/classes/{storage_anchor,context_files,material_files,storage_area}.php` und `classes/external/*.php` (Branch `moodle-native-mcp`, Stand `156516c`).
- Moodle-Core 5.x: `/opt/moodle/lib/webdavlib.php`, `/opt/moodle/repository/webdav/lib.php`, `/opt/moodle/repository/nextcloud/lib.php`, `/opt/moodle/repository/nextcloud/classes/access_controlled_link_manager.php`.
- RFC 4918 (WebDAV), RFC 4331 (DAV-Quota-Eigenschaften).

Alles, was erst eine echte Schreibprobe gegen einen laufenden Server entscheidet, ist als **offen** markiert und gehört zu [#474](https://github.com/matthiasgruenwald/Kurspilot/issues/474).

---

## 1. Der tatsächliche Verbsatz von Kurspilot

Ermittelt aus den Aufrufern, nicht aus den Klassennamen. `storage_anchor` ist rein pfad- und quotenbezogen; die echten Dateizugriffe stehen in den Endpunkten unter `classes/external/`.

| # | Operation | Wo | Was heute tatsächlich passiert |
|---|---|---|---|
| O1 | Eigener Nutzerkontext + Recht | `storage_anchor.php:88-91`, `:363-369` | `context_user::instance($USER->id)`, `require_capability('moodle/user:manageownfiles')` |
| O2 | Wurzelauflösung über Kontextpointer | `storage_anchor.php:147-185` | `get_file_storage()->get_file(...)` auf `.kurspilot-ort.json` im festen Anker, JSON-Validierung |
| O3 | Pfadzerlegung/Segmentprüfung | `storage_anchor.php:260-274`, `:283-286`, `:322-330` | rein lokal, kein Speicherzugriff |
| O4 | Ordner auflisten | `external/list_context_files.php:73-81`, `list_material_files.php:66-74`, `report_loose_material_files.php:73` | `get_directory_files(..., $recursive=false, $includedirs=true, 'filepath, filename')` |
| O5 | Listeneintrag: Name, Typ, **Größe**, **MIME-Typ**, **contenthash**, **timemodified** | `list_context_files.php:106-115`, `list_material_files.php:93-100` | direkt aus `stored_file` |
| O6 | Personenbezugsschalter beim Auflisten | `list_context_files.php:101-105` | liest **den vollen Inhalt jeder `.md`-Datei** des Ordners (`personal_data::is_marked($file->get_content())`) |
| O7 | Datei lesen | `external/read_context_file.php`, `preview_material_file.php:71-78`, `crop_material_file.php` | `get_file()` + `get_content()` |
| O8 | Schreiben/Ersetzen mit Zwischendatei | `storage_anchor.php:448-467` | `create_file_from_string()` unter `.kurspilot-neu-<uniqid>-<name>`, dann `$existing->delete()`, dann `$new->rename()` |
| O9 | Anhängen | `external/append_context_file.php:113-119` | schon heute Read-Modify-Write in **einem** Serveraufruf, ohne Lock |
| O10 | Löschen mehrerer Dateien | `external/delete_material_files.php:71-91` | erst alle auflösen (jeder fehlende Pfad bricht komplett ab), dann `$file->delete()` je Datei |
| O11 | Optimistische Gleichzeitigkeit | `write_context_file.php:118-121`, `upload_material_file.php:100-103`, `crop_material_file.php:142-154` | Vergleich `expected_contenthash` gegen `$existing->get_contenthash()` |
| O12 | Prüfsummenabgleich „verwendet/lose" | `material_files.php:443-473`, `report_loose_material_files.php:67,83` | SQL über `{files}.contenthash` im Kurskontext-Teilbaum, verglichen mit dem `contenthash` jeder Materialdatei |
| O13 | Restquote / Quotenprüfung / Warnung | `storage_anchor.php:380-405`, `material_files.php:281` | `$CFG->userquota` minus `file_get_user_used_space()`, Capability `moodle/user:ignoreuserquota` |
| O14 | Verweisweg in Aktivitäten | `material_files.php:339-379` | `create_file_from_storedfile()` aus dem Materialordner in einen Draft-Bereich |
| O15 | Ordner anlegen | — | **existiert nicht.** Moodles `filepath` ist ein Feld, kein Objekt: `create_file_from_string()` mit `filepath = '/a/b/'` legt die Ebenen implizit an |

Das Byte-Budget: `context_files::MAX_WRITE_BYTES = 1 MiB` je Vorgang (`context_files.php:58`), Zieldateien dürfen wachsen (nur ein Hinweis, `append_context_file.php:130-133`).

---

## 2. Was `\webdav_client` tatsächlich kann

Öffentlicher Verbsatz (`/opt/moodle/lib/webdavlib.php`): `open` (191), `close` (210), `check_webdav` (225), `options` (244), `mkcol` (268), `get` (307), `put` (344), `put_file` (383), `get_file` (435), `copy_file` (463), `copy_coll` (511), `move` (565), `lock` (620), `unlock` (705), `delete` (731), `ls` (804), `gpi` (892), `is_file` (924), `is_dir` (942), `mput` (966), `mget` (1023).

Wesentliche Eigenschaften, die man nur beim Lesen sieht:

- **Rohes Socket, kein cURL.** `open()` (`:191-199`) macht `fsockopen($this->_socket . $this->_server, $this->_port, ..., $this->_socket_timeout)`; Voreinstellungen `_port = 80` (`:49`), `_socket_timeout = 5` (`:54`). TLS entsteht allein über den Präfix `ssl://` (`repository/webdav/lib.php:60-80`).
- **Header sind nicht erweiterbar.** `header_add()` ist `private` (`:1322`), und `create_basic_request()` (`:1344-1360`) setzt fest nur Methode, Host, User-Agent, `Connection: TE`, `TE: Trailers` und die Authentifizierung (`basic`/`digest`/`bearer`). Ein Aufrufer kann **keinen** eigenen Anfragekopf mitgeben.
- **Antwortköpfe werden verworfen.** `process_respond()` (`:1654-1692`) baut zwar `$ret_struct['header']`, aber `get()`, `put()`, `put_file()`, `mkcol()`, `move()`, `copy_file()` geben ausschließlich `$response['status']['status-code']` zurück (z. B. `:328`, `:363-366`, `:412-415`). Nur `options()` (`:244-258`) liefert das ganze Kopf-Array.
- **`ls()` fragt einen festen Eigenschaftssatz ab.** PROPFIND, `Depth: 1` (`:812-826`), angefordert werden `getcontentlength`, `getlastmodified`, `executable`, `resourcetype`, `checked-in`, `checked-out`. **Nicht** angefordert: `getcontenttype`, `getetag`, `quota-available-bytes`. Der Parser (`:1099-1163`) kennt zwar Fälle für `creationdate` und `getcontenttype`, aber ohne Anforderung liefert ein RFC-4918-konformer Server sie nicht (RFC 4918 §9.1: benannte Eigenschaften werden angefordert, `allprop` wird hier nicht benutzt).
- **`gpi()` (`:892-919`) listet den Elternordner**, um Angaben zu **einem** Element zu bekommen. Es gibt kein `Depth: 0` irgendwo in der Datei. `is_file()`/`is_dir()` erben diesen Aufwand.
- **`delete()` und `ls()` können `die()`.** Bei einem XML-Parsefehler `die(sprintf("XML error: ..."))` (`:669`, `:766`, `:859`); `delete()` schreibt zusätzlich `print "<br>"` (`:771`) und `print 'Missing Content-Type: ...'` (`:779`, `:681`). In einem Webservice-Aufruf zerstört das die JSON-Antwort.
- **Kein Schutz gegen einen nicht geöffneten Socket.** `send_request()` (`:1460-1475`) prüft nur `_connection_closed`, nicht `$this->sock`; erst `get_respond()` (`:1497-1500`) prüft die Ressource. Wer `open()` vergisst oder dessen `false` ignoriert, läuft in `fputs(false, …)`.
- **`Destination:` ist auf `http://` verdrahtet** — `sprintf('Destination: http://%s%s', $this->_server, …)` in `copy_file` (`:467`), `copy_coll` (`:515`) und `move` (`:571`), ohne Port und ohne `https`, auch wenn die Verbindung über `ssl://` läuft.
- **`check_webdav()` (`:225-241`) sucht wörtlich `1,2`** im `DAV`-Kopf (`preg_match('/1,2/', …)`) und greift vorher ungeprüft auf `$resp['header']['DAV']` zu. Ein Server, der `1, 2` (mit Leerzeichen) oder `1, 3` meldet, gilt damit als kein WebDAV-Server.
- **Chunked-Antworten werden byteweise gelesen.** `get_respond()` (`:1530-1556`) liest bei `Transfer-Encoding: chunked` jedes Byte einzeln (`fread($this->sock, 1)` in einer `while`-Schleife). Für Materialdateien in MB-Größe ist das die relevante Kostenstelle, nicht die Latenz der Anfrage.
- **`lock()`/`unlock()` existieren** (`:620`, `:705`), werden aber von keinem Core-Repository benutzt und stehen laut Spec 0016 §5.3 ohnehin nicht zur Debatte.

**Anwendungsbelege im Core:** `repository_webdav` benutzt nur `open`, `ls`, `get_file`, `close` (`repository/webdav/lib.php:86-96`, `:109-124`) — rein lesend. `repository_nextcloud` fügt Schreiben hinzu, aber nur für den Systemaccount: `mkcol` je Ebene in einer Schleife über die Ordnernamen (`access_controlled_link_manager.php:265-281`), `copy_file`/`move` (`:185-207`), `get_file` (`:471`). Ein `put`/`put_file`-Aufruf kommt in beiden Repositories **nicht** vor — das Schreibverb hat im Core keinen einzigen Nutzer.

---

## 3. Gegenüberstellung

| Benötigte Operation | WebDAV-Entsprechung | Urteil |
|---|---|---|
| O1 Rechteprüfung | keine — Autorisierung ist die WebDAV-Anmeldung des Instanzbesitzers | **trägt nicht** (entfällt ersatzlos, Moodle-Capability verliert Wirkung am externen Ort) |
| O2 Kontextpointer lesen | `get($pfad, $buffer)` (`:307`) auf eine kleine JSON-Datei | **trägt** — offen bleibt, ob der Pointer überhaupt extern liegen soll |
| O3 Segmentprüfung | rein lokal, unverändert | **trägt** |
| O4 Ordner auflisten | `ls($pfad)`, PROPFIND `Depth: 1` (`:812`) | **trägt** |
| O5a Name, Typ | `href` + `resourcetype` (`:1113`, `:1151`) | **trägt** |
| O5b Größe | `getcontentlength` (`:1127`) | **trägt** |
| O5c Zeitstempel | `getlastmodified`, HTTP-Datum; `strtotime()` wie in `repository/webdav/lib.php:129-133` | **trägt** |
| O5d MIME-Typ | `getcontenttype` wird von `ls()` nicht angefordert (`:819-826`) | **trägt eingeschränkt** — aus der Endung ableiten (Muster: `file_extension_icon()`, `repository/webdav/lib.php:161`) |
| O5e contenthash | keine Entsprechung (siehe O12) | **trägt nicht** |
| O6 Personenbezug beim Auflisten | ein `get()` je `.md`-Datei des Ordners | **trägt eingeschränkt** — aus einem Speicherzugriff werden 1 + N |
| O7 Datei lesen | `get($pfad, $buffer)` (`:307`), Erfolg = 200 | **trägt** |
| O8 Ersetzen mit Zwischendatei | `put($temppfad, $daten)` + `move($temppfad, $zielpfad, true)` (`:344`, `:565`) | **trägt eingeschränkt** — funktional möglich, aber am externen Ort **unnötig**: Grund der Choreografie ist Moodles Dateipool-Deduplizierung (`storage_anchor.php:428-447`), die es dort nicht gibt. RFC 4918 §9.7.1: PUT ersetzt eine bestehende Ressource. Ein einzelnes `put()` ist die richtige Form; ob es atomar ersetzt, ist serverabhängig → **offen (#474)** |
| O8b Ordnerebenen implizit anlegen (O15) | `mkcol` legt genau **eine** Ebene an; RFC 4918 §9.3: „all ancestors MUST already exist, or the method MUST fail with a 409 (Conflict)". Core macht es deshalb in einer Schleife (`access_controlled_link_manager.php:265-281`) | **trägt eingeschränkt** — neue Operation nötig, die es bei Moodle-Dateien nicht gab: vor jedem Schreiben Ordnerkette prüfen/anlegen (`is_dir()` + `mkcol()` je Ebene, jeweils ein PROPFIND) |
| O9 Anhängen | kein Verb. WebDAV kennt kein APPEND; RFC 4918 definiert keine Teilaktualisierung | **trägt nicht** — Ersatz ist `get()` + Verkettung + `put()`. Kosten: die **gesamte** Journaldatei über die Leitung, zweimal, je Anhängevorgang. Bei zwei gleichzeitigen Schreibern gewinnt der letzte, ohne Kollisionsmeldung — wie heute (`append_context_file.php:39-45`), nur mit deutlich größerem Zeitfenster |
| O10 Löschen | `delete($pfad)` (`:731`) je Datei | **trägt eingeschränkt** — das Alles-oder-Nichts der Auflösung (`delete_material_files.php:68-91`) kostet ein PROPFIND je Pfad vorab; und `delete()` kann bei 207-Antworten `print`/`die()` (`:766-779`) |
| O11 Optimistische Gleichzeitigkeit | ETag/`If-Match` wäre die richtige Antwort (RFC 4918 §15.6, §10.4). `ls()` fordert `getetag` nicht an, `put()` verwirft die Antwortköpfe (`:363-366`), `header_add()` ist `private` (`:1322`) | **trägt nicht** — mit dem Core-Client wie er ist, gibt es keinen ETag und keine bedingte Anfrage. Verlorene Aktualisierungen passieren still. Ersatz nur schwach: `getlastmodified` (Sekundenauflösung) oder `getcontentlength` vor dem Schreiben vergleichen. Ein tragfähiger Weg erfordert eine Core-Änderung (MDL) oder einen eigenen Client |
| O12 Prüfsummenabgleich „lose Datei" | WebDAV kennt keine Prüfsummeneigenschaft; RFC 4918 §15 listet keine. Nextcloud/ownCloud führen `oc:checksum` als Herstellererweiterung — `webdavlib.php` fordert sie nicht an | **trägt nicht** — der Abgleich funktioniert nur, wenn jede Materialdatei geholt und lokal gehasht wird (SHA-1, wie Moodles `contenthash`). Für `report_loose_material_files` heißt das: der ganze Materialordner über die Leitung, je Aufruf |
| O13 Restquote | `DAV:quota-available-bytes` (RFC 4331 §3, Pflicht auf Collections). `ls()` fordert sie nicht an (`:819-826`), der Parser kennt sie nicht | **trägt eingeschränkt** — die Norm hat die Entsprechung, der Core-Client holt sie nicht. Ein eigener PROPFIND wäre nötig. Semantisch ist es eine andere Zusage: RFC 4331 §3 erlaubt dem Server, die Eigenschaft ganz wegzulassen, und „0" heißt dort nicht zuverlässig „Schreiben scheitert". Die Moodle-Nutzerquote (`$CFG->userquota`) fällt am externen Ort **ersatzlos** weg — das ist der Zweck der Übung (#467) |
| O14 Verweisweg in Aktivitäten | `get()` je Datei, dann `create_file_from_string()` in den Draft-Bereich statt `create_file_from_storedfile()` | **trägt** — mit Netzkosten je referenzierter Datei |
| O15 Vorschau/Zuschnitt | `get()` (`:307`), danach unverändert GD/ImageMagick | **trägt** |
| Erreichbarkeitsprüfung | `open()` (`:191`) und `check_webdav()` (`:225`) | **trägt eingeschränkt** — `check_webdav()` verlangt wörtlich `1,2` im DAV-Kopf (`:236`); welche Server das erfüllen, ist **offen (#474)** |
| MOVE/COPY über HTTPS | `move()`/`copy_file()` | **offen** — `Destination:` ist auf `http://` ohne Port verdrahtet (`:467`, `:515`, `:571`). Ob Server das tolerieren, entscheidet nur eine Schreibprobe (#474) |

---

## 4. Antworten auf die acht Fragen des Tickets

### 1. Schreiben und Ersetzen

`put($path, $data)` (`:344-368`) sendet PUT mit `Content-length` und `Content-type: application/octet-stream` und gibt den Statuscode zurück (200/201/204 bei Erfolg). RFC 4918 §9.7.1: „A PUT performed on an existing resource replaces the GET response entity of the resource." Überschreiben ist also die Normalsemantik, kein Sonderfall. `put_file()` (`:383-428`) ist dasselbe aus einer lokalen Datei; es liest allerdings mit `fgets($handle, 4096)` — zeilenweise, für Binärdateien unnötig, aber unschädlich, da `Content-length` aus `filesize()` kommt.

Die Zwischendatei-Choreografie **trägt technisch** (`put` unter Temporärnamen, dann `move(…, true)`), ist aber am externen Ort gegenstandslos. Ihr Grund steht wörtlich in `storage_anchor.php:428-447`: `stored_file::delete()` entfernt den Blob der letzten Referenz physisch aus Moodles Dateipool, und eine Transaktion holt ihn nicht zurück. Ein WebDAV-Server hat diesen Dateipool nicht. Ein einzelnes PUT ist die einfachere und richtige Form.

**Offen (#474):** ob PUT auf dem Zielserver atomar ersetzt (bei Abbruch mitten im Upload: leere/halbe Datei statt alter Inhalt) und ob `move()` über HTTPS wegen des verdrahteten `Destination: http://` scheitert.

### 2. Anhängen

Kein WebDAV-Verb. Read-Modify-Write ist der einzige Weg mit dem Core-Client. Kosten: die vollständige Journaldatei wird bei **jedem** Anhängen geladen und vollständig zurückgeschrieben — bei einem über ein Schuljahr gewachsenen Journal wächst das übertragene Volumen quadratisch mit der Zahl der Einträge. Der 1-MiB-Deckel in `context_files.php:58` gilt nur für das Anhängsel, nicht für die Zieldatei (`append_context_file.php:84-90`), und der Rotationshinweis (`:130-133`) ist heute nur ein Hinweis. Mit externem Speicher wird aus dem Hinweis ein harter Bedarf.

Zwei gleichzeitige Schreiber: der letzte gewinnt, ohne Meldung — funktional wie heute (`append_context_file.php:39-45` erklärt genau das), aber das Zeitfenster zwischen Lesen und Schreiben wächst von Millisekunden auf Netzlaufzeiten. Ohne ETag (Frage 3) bleibt es unbemerkt.

### 3. ETags und bedingte Anfragen

**Nein, beides nicht.** Drei unabhängige Sperren:

- `ls()` fordert `getetag` nicht an (`:819-826`); der Parser hat keinen Fall dafür (`:1112-1157`).
- `put()`/`get()` geben nur den Statuscode zurück und verwerfen den `ETag`-Antwortkopf (`:363-366`, `:328`), obwohl `process_respond()` ihn einsammelt (`:1667-1682`).
- `header_add()` ist `private` (`:1322`); es gibt keinen öffentlichen Weg, `If-Match` oder `If` (RFC 4918 §10.4) mitzugeben.

Folge: verlorene Aktualisierungen passieren **still**. Das heutige `expected_contenthash` (`write_context_file.php:118-121`) hat am externen Ort keine Entsprechung. Schwacher Ersatz: `getlastmodified`/`getcontentlength` aus einem PROPFIND vor dem Schreiben — Sekundenauflösung, und zwei gleich große Änderungen in derselben Sekunde entgehen ihm. Ein echter Ersatz verlangt einen erweiterten Client (Core-Patch oder eigener).

### 4. Auflisten

`ls()` liefert je Eintrag: `href`, `getcontentlength`, `lastmodified`, `resourcetype` (`collection` oder fehlend), `status`, dazu Lock-Angaben (`:1112-1157`). Der erste Eintrag ist die Collection selbst; `repository/webdav/lib.php:144` filtert ihn über `$path != $v['href']`.

Für `list_context_files`/`list_material_files` reicht das für Name, Typ, Größe und Zeitstempel. **Es fehlt** der MIME-Typ (aus der Endung abzuleiten, so macht es auch `repository/webdav/lib.php:161`) und der `contenthash` (Frage 5). Der Personenbezugsschalter (`list_context_files.php:101-105`) braucht zusätzlich den Inhalt jeder `.md` — aus einem Speicherzugriff werden 1 PROPFIND + N GET.

### 5. Prüfsummen

**Nichts Brauchbares.** RFC 4918 §15 definiert keine Prüfsummeneigenschaft; `getetag` (§15.6) ist ein serverdefinierter Wert („Contains the ETag header value … as it would be returned by a GET"), ausdrücklich kein Inhaltshash und zwischen Servern nicht vergleichbar. Nextcloud/ownCloud führen `oc:checksum` als Herstellererweiterung — `webdavlib.php` fordert sie nicht an, und die Karte hat mehr Server als Nextcloud im Blick (#467).

`material_files::used_contenthashes()` (`:443-473`) vergleicht gegen Moodles `{files}.contenthash` (SHA-1 des Inhalts). Damit `report_loose_material_files` weiterarbeitet, muss **jede** Materialdatei geholt und lokal gehasht werden. Das ist der teuerste Einzelposten der ganzen Umstellung.

### 6. Anlegen von Ordnern

`mkcol()` (`:268-294`) legt **eine** Ebene an. RFC 4918 §9.3: „When the MKCOL operation creates a new collection resource, all ancestors MUST already exist, or the method MUST fail with a 409 (Conflict)." Der Kommentar im Core sagt dasselbe (`:286-287`). Belegt durch die Praxis: `repository_nextcloud` läuft in einer Schleife über die Ordnernamen und ruft `is_dir()` + `mkcol()` je Ebene (`access_controlled_link_manager.php:265-281`).

Das ist eine **neue** Operation. Moodles `filepath` ist ein Feld, kein Objekt: `create_file_from_string()` mit `filepath = '/faecher/mathe/'` legt die Ebenen implizit an, ohne dass Kurspilot je einen Ordner erzeugen musste. Über WebDAV braucht jedes Schreiben in einen neuen Unterordner vorher eine Ordnerkette — mit einem PROPFIND je Ebene (`is_dir()` ruft `gpi()` ruft `ls()`).

### 7. Fehlerbilder

Was der Client wirklich unterscheidet:

| Lage | Signal |
|---|---|
| Server nicht erreichbar / TLS scheitert | `open()` gibt `false` (`:191-208`), `_socket_timeout = 5` Sekunden (`:54`) |
| Kein WebDAV-Server | `check_webdav()` gibt `false` (`:225-241`) — mit dem `1,2`-Vorbehalt |
| Anmeldung falsch | Statuscode `401` aus der jeweiligen Methode; `get_respond()` protokolliert das nur (`:1522-1524`) |
| Pfad fehlt (Lesen) | `get()` gibt `404` |
| Elternordner fehlt (Schreiben) | `put()` gibt `409`, `mkcol()` gibt `409` (RFC 4918 §9.3, §9.7.1) |
| Schreibschutz / verboten | `403` |
| Ziel existiert, `Overwrite: F` | `412` — Core wertet das aus (`access_controlled_link_manager.php:187-206`) |
| Gesperrt | `423` |
| Speicher voll | `507 Insufficient Storage` (im Core kommentiert: `:290-291`) |

Die Unterscheidung ist also da, aber **roh**: der Client liefert nackte Zahlen, kein typisiertes Ergebnis, und trennt „HTTP-Antwort mit Fehlerstatus" von „keine HTTP-Antwort" nur über `false`. Kurspilot müsste die Zuordnung Statuscode → Lehrkraft-Deutsch selbst tragen. Drei Fallen dabei:

- `delete()` gibt bei Erfolg ein **Array** zurück (`:786-788`), bei 207 ein anderes Array, bei Nicht-HTTP **`null`** (kein `return` am Ende, `:789-793`) — anders als jede andere Methode.
- `delete()` und `ls()` können `die()` und `print` (`:669`, `:766-779`, `:859`). In einem Webservice zerstört das die Antwort, bevor irgendein Fehlerbild entstehen kann.
- `send_request()` prüft `$this->sock` nicht (`:1460-1475`). Ein ignoriertes `open() === false` endet nicht in einem Fehlerbild, sondern in einem PHP-Fehler.

Für die Ausfall-Entscheidung der Karte (#467: kein Rückfall, Fehler geht an die KI, Ausstandsnotiz im Anker) ist die brauchbare Grenze deshalb grob und tragfähig zugleich: **`open()`/`check_webdav()` scheitert oder Statuscode ≥ 500 → „Speicher antwortet nicht"**; `401/403` → „Zugang stimmt nicht"; `404/409` → „Ort gibt es nicht (mehr)"; `507` → „Speicher voll". Feiner sollte die Spec nicht werden, solange die Zuordnung nicht gegen echte Server geprüft ist (#474).

### 8. Was ersatzlos wegfällt

- **Die Moodle-Nutzerquote.** `remaining_quota()` (`storage_anchor.php:380-388`) rechnet `$CFG->userquota` minus `file_get_user_used_space()` und respektiert `moodle/user:ignoreuserquota`. Am externen Ort gilt keine davon — genau das ist der Zweck der Karte.
- **Eine Entsprechung gibt es dem Namen nach:** `DAV:quota-available-bytes` (RFC 4331 §3), Pflicht auf Collections, Angabe in Oktetten. Sie ist aber weder gleichwertig noch abrufbar: `ls()` fordert sie nicht an (`:819-826`); RFC 4331 §3 erlaubt dem Server, sie bei „infinite limits" ganz wegzulassen (404 im Multi-Status) und lässt ihm die Wahl zwischen mehreren überlappenden Grenzen; und „0" heißt dort ausdrücklich nur, dass weitere Zuteilungen „probably" scheitern, während Überschreiben gleicher Größe funktionieren kann. Als **Vorabprüfung** vor dem Schreiben (`require_quota()`, `storage_anchor.php:397-405`) taugt sie damit nicht; als **Anzeige** (`list_material_files.remaining_quota_mb`) taugt sie, wenn der Server sie liefert.
- **`moodle/user:manageownfiles`** verliert am externen Ort jede Wirkung. Wer die Repository-Instanz besitzt, schreibt.
- **Der Moodle-Dateipool** und damit Deduplizierung, `contenthash` (Frage 5) und der Grund für die Zwischendatei (Frage 1).

**Praktische Folgerung:** Quotenprüfung wird vom Vorher-Verbot zum Nachher-Fehlerbild — statt `require_quota()` gilt `507` aus dem PUT. Ob der jeweilige Server `507` statt `403` liefert, ist **offen (#474)**.

---

## 5. Was offen bleibt

| Offen | Warum es nur eine Schreibprobe klärt (#474) |
|---|---|
| Ersetzt PUT atomar? | Abbruch mitten im Upload ist serverseitiges Verhalten, im Client nicht sichtbar |
| Funktioniert MOVE/COPY über HTTPS? | `Destination: http://…` ohne Port ist verdrahtet (`:467`, `:515`, `:571`); Toleranz ist serverabhängig |
| Erkennt `check_webdav()` reale Server? | wörtlicher `1,2`-Vergleich (`:236`); reale DAV-Köpfe variieren in Trennzeichen und Klassen |
| Liefert der Server `quota-available-bytes`? | RFC 4331 §3 macht es auf Collections zur Pflicht, erlaubt aber Weglassen bei „infinite limits" |
| Kommt `507` bei vollem Speicher? | Server liefern in der Praxis auch `403`/`413` |
| Liefert PROPFIND `getcontenttype`/`getetag`, wenn man sie anfordert? | erfordert einen erweiterten Client — die Core-Anfrage fragt sie gar nicht ab |
| Latenzbudget | #467 „Not yet specified"; hängt an der byteweisen Chunked-Lesart (`:1530-1556`) und an der PROPFIND-Zahl je Vorgang |
| Verschlüsselung des Instanzpassworts | `repository_webdav` speichert `webdav_password` als Instanzoption (`repository/webdav/lib.php:172-173`, `:200-201`); ob Kurspilot es auslesen darf, ist eine Datenschutzfrage der Spec, keine Verbfrage |

---

## 6. Verdichtetes Urteil

`\webdav_client` trägt den **lesenden** Verbsatz von Kurspilot vollständig und den **schreibenden** im Kern (PUT, DELETE, MKCOL, MOVE). Er trägt **nicht**:

1. **Anhängen** — kein Verb, Read-Modify-Write mit vollem Datei-Roundtrip.
2. **Kollisionserkennung** — kein ETag, kein `If-Match`, `header_add()` ist `private`. Verlorene Aktualisierungen werden still.
3. **Prüfsummen** — kein Protokoll-Gegenstück; „lose Materialdatei" verlangt, den ganzen Ordner zu holen und zu hashen.
4. **Quotenvorabprüfung** — `quota-available-bytes` existiert (RFC 4331), wird vom Core-Client nicht abgefragt und ist semantisch keine Zusage.

Dazu kommen zwei **neue** Pflichten, die es bei Moodle-Dateien nicht gab: Ordnerketten von Hand anlegen (MKCOL je Ebene) und MIME-Typen aus Endungen ableiten. Und drei Robustheitsmängel des Core-Clients, die in einem Webservice zählen: `die()`/`print` in `delete()`/`ls()`, das abweichende Rückgabeformat von `delete()` und der ungeprüfte Socket in `send_request()`.

Keiner dieser Punkte macht die Karte unmöglich. Zusammen entscheiden sie aber, was die Spec zusagen darf: **kein Kollisionsschutz am externen Ort**, **kein Quotenversprechen vor dem Schreiben**, **Journalrotation wird Pflicht statt Hinweis**, und **`report_loose_material_files` braucht entweder ein lokales Prüfsummengedächtnis oder eine ehrliche Absage**.
