# ADR 0035 — WebDAV-Version: starkes ETag oder Prüfsumme der Bytes

Status: angenommen (Spec 0030). Übernommen aus E3 und E9 des moocp-contextmanager; ändert die
Prüfwert-Regel des WebDAV-Adapters aus Spec 0021.

## Kontext

Der WebDAV-Adapter bildete den Prüfwert aus dem ETag, ohne ETag aus der Änderungszeit.
Die Änderungszeit hat eine Auflösung von einer Sekunde; IServ sendet kein ETag. Ein schwaches
ETag (`W/…`) ging als `If-Match` an den Server, obwohl `If-Match` stark vergleicht.

## Entscheidung

1. **Starkes ETag** (ohne `W/`): Der Prüfwert kommt aus dem ETag; ersetzt wird mit `If-Match`,
   angelegt mit `If-None-Match: *`. Der Server setzt die Bedingung durch.
2. **Kein oder schwaches ETag:** Der Prüfwert ist `sha256:<hex>` der gelesenen Bytes. Vor
   `PUT` (und `MOVE`, ADR 0036) liest der Adapter die Datei erneut und vergleicht; weicht sie ab
   oder fehlt sie, ist das ein Konflikt. Dann folgt die Schreibung ohne `If-Match`.
3. **Ehrliche Antwort.** Zwischen Vergleich und `PUT` bleibt ein Wettlauffenster. Jede Antwort,
   bei der der Server die Bedingung nicht durchgesetzt hat, trägt `server_enforced: false` und
   einen Satz dazu; sie behauptet nie das Gegenteil. `verified` heißt weiter nur „zurückgelesen“.
4. **Auflisten** liest keine Inhalte. Einträge ohne starkes ETag tragen einen leeren Prüfwert;
   die Version liefert `read_context_file`.

## Considered Options

- Änderungszeit behalten: zwei Änderungen in derselben Sekunde blieben unbemerkt.
- Am Server ohne ETag nur lesen: Umbau und Journal brauchen Schreiben.
- `LOCK`: Server verhalten sich unterschiedlich, eine vergessene Sperre blockiert die Datei.
- Prüfsumme auch bei starkem ETag: der Server schließt dort das Fenster, das wäre ein Rückschritt.

## Consequences

- Prüfwerte aus älteren Lesevorgängen am Ort ohne ETag passen nicht mehr: einmal neu lesen.
- Auflistungen am IServ-Ort liefern keinen Prüfwert mehr; der Abgleich von Handänderungen
  braucht dort ein Lesen.
- Der Markierungs-Speicher (Personenbezug beim Auflisten) schlüsselt Einträge ohne Prüfwert nach Pfad, Größe und Änderungszeit (Ersatzwert `mtime:<Zeit>`); das entspricht dem Sicherheitsniveau vor #780, und eine unveränderte Datei wird nicht bei jedem Auflisten erneut gelesen.
