# Recherche: Zugangsdaten einer `repository_webdav`-Nutzerinstanz auslesen

**Ticket:** [#468](https://github.com/matthiasgruenwald/Kurspilot/issues/468) — Recherche zur Karte [#467](https://github.com/matthiasgruenwald/Kurspilot/issues/467)
**Stand:** 2026-09-09
**Primärquelle:** Moodle-Core-Quellcode unter `/opt/moodle`, `$release = '5.0.8 (Build: 20260608)'` (`/opt/moodle/version.php:35`).
Alle Zeilenangaben beziehen sich auf diesen Baum. Ergänzend die Moodle Developer Docs
(<https://moodledev.io/docs/5.0/apis/plugintypes/repository>). Keine Sekundärquellen.

**Ablageort dieses Dokuments:** das Repo kannte bisher kein Verzeichnis für Rechercheergebnisse
(`docs/` enthält `adr/`, `agents/`, `diagrams/`, `plans/`, `specs/`). Neu angelegt als `docs/research/`.

---

## Kurzantwort

Technisch geht es: `get_option()` gibt die Zugangsdaten im Klartext heraus, ohne jede Zugriffsprüfung.
Rechtlich-organisatorisch trägt die Bauform aber nur mit **zwei** Adminschritten statt einem — neben
`enableuserinstances` muss die Schule auch die Capability `repository/webdav:view` so setzen, dass sie
im **Nutzerkontext** greift. Genau das tut `repository_webdav` von sich aus nicht, im Unterschied zu
`repository_dropbox` und `repository_nextcloud`. Ohne diesen zweiten Schritt sieht die Lehrkraft eine
leere Seite ohne Anlegen-Link. Details unter [Frage 5](#5-berechtigungen).

---

## 1. Der technische Weg

### 1.1 Von der angemeldeten Person zu ihren Instanzen

`repository::get_instances()` (`repository/lib.php:1018`) baut die Abfrage über
`{repository}` und `{repository_instances}` (`:1076–1078`). Für Nutzerinstanzen ist der
**Kontext** der Filter:

```php
$params = [
    'context'        => [context_user::instance($USER->id)],
    'currentcontext' => $currentcontext,
    'type'           => 'webdav',
];
```

Zwei Fallstricke, beide im Code belegt:

- **Das Argument `userid` hilft nicht.** Es filtert auf `i.userid = 0 or i.userid = :userid`
  (`repository/lib.php:1086–1089`), aber Nutzerinstanzen werden mit `userid = 0` angelegt:
  `repository/manage_instances.php:177` ruft
  `repository::static_function($plugin, 'create', $plugin, 0, context::instance_by_id($contextid), $fromform)`
  auf, und `repository::create()` schreibt dieses `0` unverändert nach `$record->userid`
  (`repository/lib.php:1965`). **Die Eigentümerschaft steckt allein in `contextid`**, das auf den
  Nutzerkontext zeigt (`:1963`). Die Spalte `userid` in `repository_instances`
  (`lib/db/install.xml:2722`) ist für Nutzerinstanzen aus dem Formularweg tot.
- **`get_instances()` filtert selbst nach Capability**, und zwar im `currentcontext`, nicht im
  Instanzkontext: `has_capability('repository/'.$record->repositorytype.':view', $current_context)`
  (`repository/lib.php:1138`). Siehe [Frage 5](#5-berechtigungen).

Der zweite Weg ist `repository::get_repository_by_id($id, $context)` (`repository/lib.php:594`).
Er prüft **keine** Rechte — der Doc-Block sagt das ausdrücklich: „Note that this function does not
check permission to access repository contents" (`:585`). Ungültige ID → `repository_exception('invalidrepositoryid')`
(`:611`).

Nicht benutzen: `repository::get_instance($id)` (`:1165`). Es setzt hart den Systemkontext; der
Doc-Block warnt: „Do not use this function to access repository contents, because it does not set
the current context" (`:1157–1158`).

### 1.2 Von der Instanz zu den Feldern

`repository_webdav::get_instance_option_names()` (`repository/webdav/lib.php:172–174`) listet genau
die sieben Felder aus dem Ticket:

```php
return array('webdav_type', 'webdav_server', 'webdav_port', 'webdav_path',
             'webdav_user', 'webdav_password', 'webdav_auth');
```

`repository::get_option()` (`repository/lib.php:2108–2130`) liest sie aus
`{repository_instance_config}` und gibt sie **im Klartext** zurück — kein Entschlüsseln, kein
Maskieren, keine Rechteprüfung, `public`. Gespeichert werden sie von `set_option()`
(`:2076–2098`) ebenso unverändert; das Schema kennt nur `instanceid`, `name`, `value` (TEXT)
(`lib/db/install.xml:2736–2746`). `passwordunmask` im Formular (`repository/webdav/lib.php:200–201`)
ist reine Anzeigemaskierung.

Zusätzlich: der Basiskonstruktor lädt alle Instanzoptionen in die **public** Eigenschaft `$options`
(`repository/lib.php:525` Deklaration, `:566–576` Befüllung). `$repo->options['webdav_password']`
liefert dasselbe Klartextpasswort.

### 1.3 Vom Feld zur Verbindung

Der `webdav_client` in `repository_webdav` ist `protected` (`repository/webdav/lib.php:49`) — er ist
nicht wiederverwendbar. Ein eigener Client (`lib/webdavlib.php:115`) ist zu bauen:

```php
new webdav_client($server, $user, $pass, $auth, $socket /* '' oder 'ssl://' */, $oauthtoken);
```

Die Ableitung von Protokoll und Port muss aus `repository/webdav/lib.php:60–81` **nachgebaut** werden,
sie steckt nicht in den Optionen:

- `webdav_type` leer → `''` (Klartext), sonst `'ssl://'` (`:60–64`);
- `webdav_port` leer → 80 bzw. 443 je nach Typ (`:65–76`);
- `webdav_auth == 'none'` → `false` statt `'none'` an den Client (`:57–59`).

Der Verbsatz für Schreibzugriffe ist vorhanden: `open()` (`lib/webdavlib.php:191`), `mkcol()` (`:268`),
`put()` (`:344`), `put_file()` (`:383`), `get_file()` (`:435`), `move()` (`:565`), `delete()` (`:731`),
`ls()` (`:804`).

**Fazit Frage 1:** Der Weg existiert, ist knapp und benutzt nur public API. `get_option()` liefert
Klartext.

---

## 2. Präzedenz im Core

**Gesucht:** liest irgendein Core-Code die Instanzoptionen eines *fremden* Repository-Plugins?

`grep -rn "repository_instance_config" --include=*.php` über `/opt/moodle` findet außerhalb von
`repository/lib.php` und Tests nur zwei Treffer, und beide sind **Ausschlusslisten**:

- `lib/adminlib.php:9302` — `db_should_replace()` überspringt die Tabelle bei DB-weiten Such-/Ersetzen-Läufen;
- `admin/tool/httpsreplace/classes/url_finder.php:137` — dieselbe Tabelle auf der Skip-Liste.

Der Core behandelt `repository_instance_config` also als Tabelle, die man von außen nicht anfasst.

`repository::get_repository_by_id()` wird außerhalb des Repository-Subsystems genau einmal benutzt:
`mod/scorm/mod_form.php:398` — und dort nur, um `supports_relative_file()` abzufragen, also eine
Fähigkeit, keine Zugangsdaten.

Die Developer Docs formulieren `get_option()` ausdrücklich als plugin-internes Werkzeug:

> „Both global and instance settings can be retrieved, **from within the plugin**, via
> `$this->get_option('settingname')` and updated via `$this->set_option([...])`."
> — <https://moodledev.io/docs/5.0/apis/plugintypes/repository> (Hervorhebung hier)

**Ergebnis:** Kein Präzedenzfall im Core. Ein ausdrückliches *Verbot* habe ich ebenfalls **nicht**
gefunden — weder in der Repository-API-Doku noch in den Coding Guidelines. Die Formulierung „from
within the plugin" ist eine Beschreibung des Normalfalls, kein Verbotssatz, und `get_option()` ist
`public` und nicht `final`.

**Offen:** Ob ein Review durch die Moodle-Plugin-Datenbank so etwas beanstanden würde, lässt sich
aus dem Code nicht klären. Ein Präzedenzfall in verbreiteten Contrib-Plugins wurde nicht gesucht —
dafür fehlt hier eine lokale Contrib-Sammlung; eine Websuche wäre Sekundärquelle.

---

## 3. Der Adminschalter `enableuserinstances`

**Standardwert `0`, belegt:** `repository_type::__construct()` setzt die Option auf `0`, wenn sie in
den Typoptionen fehlt (`repository/lib.php:154–158`). Ausgewertet wird sie in
`repository_type::get_contextvisibility()` (`:108–110`): im `CONTEXT_USER` entscheidet allein
`enableuserinstances`.

**Wo sie gesetzt wird:** `repository_type_form` hängt die Checkbox an das Typformular, sobald der
Plugintyp Instanzoptionen kennt (`repository/lib.php:3037–3052`). Beschriftung aus
`lang/en/repository.php:111`: „Allow users to add a repository instance into the user context"
(`repository_webdav` bringt keine eigene Übersetzung mit, der `string_exists`-Zweig `:3049` greift
also nicht). Gespeichert wird per `set_config($name, $value, $this->_typename)`
(`repository_type::update_options()`, `:310–312`), also nach `config_plugins` mit `plugin = 'webdav'`.

**Weg in der Oberfläche:** Website-Administration → Plugins → Repositories
(Adminkategorie `repositorysettings`, `admin/settings/plugins.php:43`) → Eintrag „WebDAV repository"
bearbeiten; die Seite ist `admin/repository.php?action=edit&repos=webdav` (`admin/repository.php:57–83`).

**Was die Lehrkraft sieht, wenn es aus ist:** gar nichts. Der Navigationsknoten
„Repositories → Manage instances" im eigenen Profil hängt an
`repository::get_editable_types($usercontext)` (`lib/navigationlib.php:5591–5605`), und das prüft
`get_contextvisibility()` (`repository/lib.php:994`). Ist `enableuserinstances = 0`, ist die Liste
leer und der Knoten erscheint nicht. Ruft jemand `repository/manage_instances.php` mit `new=webdav`
von Hand auf, greift `:110–114` und wirft `usercontextrepositorydisabled` — „You cannot edit this
repository in user context" (`lang/en/repository.php:257`).

---

## 4. Sicherheitslage

**Ausgangslage:** Das Passwort liegt bereits heute unverschlüsselt in
`repository_instance_config.value` (`lib/db/install.xml:2736–2746`, geschrieben von
`repository/lib.php:2087–2094`). Daran ändert unsere Nutzung nichts.

Was sich ändert, ist **wo überall** das Geheimnis auftaucht:

| Weg | Heute | Mit Kurspilot |
|---|---|---|
| DB | Klartext | unverändert |
| Prozessspeicher | nur in Filepicker-/`repository_ajax.php`-Requests | in jedem MCP-Werkzeugaufruf, der den Kontextbereich anfasst |
| MUC-Cache | `repositories` ist `MODE_REQUEST` (`lib/db/caches.php:244–246`), also nur im Request | unverändert, **solange wir nicht selbst zwischenspeichern** |
| Logs | `webdav_client::_error_log()` schreibt nur bei `$_debug` (`lib/webdavlib.php:45`, `:1755–1762`); `repository_webdav` setzt `debug = false` (`repository/webdav/lib.php:81`) | unser eigener Client muss dasselbe tun |
| Netz | Basic-Auth-Header `base64("user:pass")` (`lib/webdavlib.php:1352`), Digest als Alternative (`:1450`) | unverändert |

Drei Punkte, die für die Spec zu Regeln werden müssen:

1. **Nie zwischenspeichern.** Bei jedem Zugriff frisch aus `get_option()` lesen. Der Request-Cache
   des Core hält nichts über den Request hinaus; ein eigener Cache oder ein Session-Feld würde das
   Geheimnis erstmals persistieren.
2. **Fehlerpfade sind der eigentliche Leckweg.** Verbindungsfehler dürfen weder Benutzername noch
   Passwort noch einen Auth-Header in MCP-Antworten, Exceptions oder das Moodle-Log tragen. Zu melden
   ist der Instanzname und der Statuscode, nichts weiter.
3. **Der `loggedinas`-Schutz wird umgangen.** `repository::check_capability()` sperrt Nutzerinstanzen
   für „Login as"-Sitzungen (`repository/lib.php:766–770`). Wer `get_option()` direkt liest, hat
   diese Prüfung nicht. Sie ist also selbst zu ziehen — `\core\session\manager::is_loggedinas()`.

**Privacy-Provider:** `repository_webdav` ist ein `null_provider`
(`repository/webdav/classes/privacy/provider.php`) mit dem Begründungstext „The WebDAV repository
plugin does not store any personal data, but it is transmitted from Moodle to the remote system"
(`repository/webdav/lang/en/repository_webdav.php:40`). Für Nutzerinstanzen mit persönlichen
Zugangsdaten ist diese Einstufung diskutabel, aber sie ist der Core-Stand; wir erben sie, ändern sie
aber nicht.

---

## 5. Berechtigungen

### 5.1 Die Capability — hier liegt der Haken

`repository_webdav` definiert `repository/webdav:view` mit den Archetypen `coursecreator`,
`teacher`, `editingteacher`, `manager` (`repository/webdav/db/access.php:30–39`).

Zum Vergleich, dieselbe Capability bei den Plugins, die Nutzerinstanzen praktisch benutzen:

- `repository/dropbox:view` → `'user' => CAP_ALLOW` (`repository/dropbox/db/access.php:33–35`)
- `repository/nextcloud:view` → `'user' => CAP_ALLOW` (`repository/nextcloud/db/access.php:31–33`)

Der Archetyp `user` steht für die Rolle „Authentifizierter Nutzer", die im Systemkontext zugewiesen
ist und damit **auch im Nutzerkontext** greift. Die Rollen `teacher`/`editingteacher` sind dagegen im
Kurskontext zugewiesen und liegen nicht im Kontextpfad des eigenen Nutzerkontexts.

**Folge:** `has_capability('repository/webdav:view', context_user::instance($USER->id))` ist für eine
Lehrkraft standardmäßig `false`. Und daran hängen alle drei Wege:

- Der Anlegen-Link wird ausgeblendet — mit einem Kommentar, der genau diesen Fall beschreibt:
  „If the user does not have the permission to view the repository, it won't be displayed in the list
  of instances." (`repository/lib.php:1581–1585`).
- `repository/manage_instances.php:110–114` wirft `usercontextrepositorydisabled`.
- `repository::get_instances()` liefert die Instanz nicht zurück (`repository/lib.php:1138`).

Der Navigationsknoten erscheint trotzdem, weil er nur `get_contextvisibility()` prüft
(`lib/navigationlib.php:5591–5605`). Die Lehrkraft landet also auf einer Seite mit leerer Tabelle und
ohne Anlegen-Link.

**Damit braucht die Karte zwei Adminschritte, nicht einen:**

1. `enableuserinstances` für den Typ WebDAV setzen (Frage 3);
2. `repository/webdav:view` einer Rolle erlauben, die im Nutzerkontext greift — im Regelfall
   „Authentifizierter Nutzer" (Website-Administration → Nutzer → Rechte → Rollen verwalten).

**Offen / nicht verifiziert:** Diese Ableitung stammt aus dem Code, nicht aus einer laufenden
Instanz. Sie setzt voraus, dass die Schule keine abweichenden Rollenzuweisungen im Systemkontext hat
(eine im Systemkontext zugewiesene Teacher-Rolle würde denselben Effekt haben wie Schritt 2). Vor
dem Schreiben der Spec sollte das an einer echten Instanz nachgespielt werden.

### 5.2 Eigentumsprüfung

Zwei Core-Prüfungen decken „gehört mir" ab, beide gebunden an eine Instanz, die man mit dem
richtigen Kontext konstruiert hat:

- `repository::check_capability()` (`repository/lib.php:753–802`): sperrt bei „login as"
  (`:766–770`) und stellt für Nutzerinstanzen sicher, dass `$repocontext->instanceid == $USER->id`
  (`:775–780`).
- `repository::can_be_edited_by_user()` (`:1828–1850`): ruft `check_capability()` und wiederholt die
  Kontextprüfung `:1839`.

Beide gelten dem *Bearbeiten* bzw. *Erkunden* der Instanz. **Für das Lesen der Optionen gibt es keine
eigene Prüfung** — `get_option()` (`:2108`) ist ungeschützt.

**Für `local_kurspilot` heißt das:** eine vom Client gelieferte Instanz-ID darf nie ungeprüft an
`get_repository_by_id()` gehen. Entweder über `get_instances()` im eigenen Nutzerkontext auflisten
und nur daraus wählen, oder hart prüfen:

```php
$repo->instance->contextid === context_user::instance($USER->id)->id
```

plus `!\core\session\manager::is_loggedinas()`.

---

## 6. Lebensdauer

**Was ist der stabile Griff?** Nur `repository_instances.id` (`lib/db/install.xml:2717–2734`).

- **Name:** frei änderbar über `set_option(['name' => ...])` (`repository/lib.php:2079–2085`), also
  als Griff untauglich.
- **Passwortwechsel:** unproblematisch, solange wir bei jedem Zugriff frisch lesen — der
  `repositories`-Cache ist `MODE_REQUEST` (`lib/db/caches.php:244–246`). Das ist gleichzeitig die
  Begründung für Regel 1 aus Frage 4.
- **Löschen:** `repository::delete()` entfernt beide Zeilen (`repository/lib.php:1999–2014`). Der
  zweite Weg ist `delete_all_for_context()` (`:2028–2040`), aufgerufen aus
  `lib/classes/context.php:600` beim Löschen des Kontexts, also beim Löschen der Nutzerin.
- **Kein Signal.** In `repository/classes/` liegt nur `privacy/`; ein `repository_instance_*`-Event
  existiert im Core nicht (`lib/classes/event/` enthält keins). **Ein Pointer auf eine gelöschte
  Instanz verwaist still.** Wir merken es erst beim nächsten Zugriff:
  `repository_exception('invalidrepositoryid')` (`repository/lib.php:611`).
- **Umkonfigurieren ist der gefährlichere Fall.** Ändert die Lehrkraft `webdav_server` oder
  `webdav_path`, bleibt die ID gültig, aber die Wurzel liegt woanders. Ein Pointer, der nur einen
  relativen Pfad speichert, zeigt danach ohne jeden Fehler auf einen anderen Ort.

**Bezug zum bestehenden Kontextpointer:** `.kurspilot-ort.json` (`storage_anchor::POINTER_FILENAME`,
`Plugin/src/local_kurspilot/classes/storage_anchor.php:72`) trägt heute die Pflichtfelder
`kontextbereich` und `materialordner` (`:80`) — reine Pfade innerhalb der Private Files. Für einen
externen Ort reicht das nicht.

**Empfehlung für die Spec:** Der Pointer speichert die Instanz-ID, den relativen Pfad **und** eine
Kopie von `webdav_server` + `webdav_path` als Prüfmerkmal. Weicht das Prüfmerkmal beim Auflösen ab,
ist das ein harter Fehler mit Ansage an die KI — nie eine stille Umleitung. Das entspricht der
Linie, die `storage_anchor::root()` schon fährt: „nie ein stiller Rueckfall auf die Standardwurzel,
sobald der Pointer existiert, aber fehlerhaft ist" (`storage_anchor.php:104–106`).

---

## 7. Bewertung der Bauform

**Trägt.** Der Kern der Karte — Zugangsdaten aus der Nutzerinstanz wiederverwenden statt ein eigenes
Verbindungsformular zu bauen — ist technisch sauber über public API erreichbar und braucht weder
Reflection noch direkten DB-Zugriff noch einen Core-Patch.

Zu korrigieren ist die Annahme, `enableuserinstances` sei der einzige Adminschritt. Es sind zwei
(Frage 5). Das verschiebt die Karte nicht, aber es gehört in die Spec und in die Einrichtungsanleitung
für die Schule.

Drei Dinge, die die Spec ohnehin regeln muss und die aus dieser Recherche konkret werden:

1. Eigentumsprüfung selbst ziehen, inklusive `is_loggedinas` (Frage 5.2).
2. Zugangsdaten nie zwischenspeichern, nie in Fehlermeldungen (Frage 4).
3. Pointer mit Prüfmerkmal statt bloßer Instanz-ID (Frage 6).

**Der OAuth2-Weg von `repository_nextcloud` ist keine gleichwertige Alternative** und muss nicht
weiter geprüft werden, solange der WebDAV-Weg steht: `repository_nextcloud::create()` verlangt
`moodle/site:config` (`repository/nextcloud/lib.php:661`), und `instance_config_form()` zeigt Nicht-Admins
nur eine „nopermissions"-Meldung (`:673–678`). Die Instanzoption ist eine `issuerid`
(`:749–752`), die auf einen vom Admin unter `admin/tool/oauth2/issuers.php` angelegten Issuer zeigt.
Nutzerinstanzen sind damit faktisch ausgeschlossen — es ist ein reiner Adminweg und zusätzlich
Nextcloud-only.

---

## Was offen bleibt

- **Verifikation an einer laufenden Instanz.** Die Capability-Ableitung in Frage 5 ist aus
  `db/access.php` und dem Kontextpfad geschlossen, nicht nachgespielt. Nächster Schritt: auf der
  Spike-Instanz `enableuserinstances` setzen und prüfen, ob eine Lehrkraft ohne zusätzliche
  Rollenanpassung eine WebDAV-Nutzerinstanz anlegen kann.
- **Contrib-Präzedenz.** Ob ein verbreitetes Contrib-Plugin fremde Instanzoptionen liest, wurde nicht
  geklärt — dafür fehlt eine lokale Contrib-Sammlung, und eine Websuche wäre Sekundärquelle.
- **Haltung der Moodle-Plugin-Review.** Aus Code und Developer Docs nicht ableitbar. Relevant nur,
  falls `local_kurspilot` je in die Plugin-Datenbank soll.
- **Ordnerwahl.** `repository_webdav::get_listing()` liefert Ordner mit `path`
  (`repository/webdav/lib.php:144–153`), aber über den Repository-Client, nicht über einen eigenen
  `webdav_client`. Ob wir für die Ortswahl `get_listing()` der Instanz benutzen (dann greift die
  Capability-Prüfung von `check_capability()`) oder selbst `ls()` fahren, ist eine Spec-Entscheidung
  und hier nicht entschieden.
