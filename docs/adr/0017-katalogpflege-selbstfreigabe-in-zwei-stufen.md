# Katalogpflege: Selbstfreigabe in zwei Stufen statt Voll-Fail-closed

Der Feldkatalog (Spec 0015) ist die Grenze dessen, was Kurspilot schreiben kann — und er ist überwiegend eine **Kopie** von Moodle-Interna: außer `format_text_menu()` trägt keine aufrufbare Quelle über mehr als einen Modultyp, für `assign` gibt es bei 34 Konstanten null aufrufbare Wertemengen. Ein solcher Katalog **veraltet still**. Wir entscheiden: nach jedem erkannten Moodle-Versionswechsel läuft eine Tiefenprüfung **je Modultyp**; grün heißt schreibbar mit Status „automatisch geprüft", Drift sperrt **genau diesen** Modultyp fürs Schreiben, während Lesen weiterläuft. Ein manuelles Review je Major-Release hebt auf „geprüft".

## Considered Options

- **Voll-Fail-closed**: jeder Versionswechsel sperrt den gesamten Schreibpfad, bis ein Mensch den Katalog freigibt. Verworfen: ein Point-Release, das an `mod_page` nichts ändert, nähme der Lehrkraft mitten im Schuljahr alle acht Modultypen weg. Der Schaden der Sperre wäre größer als der Schaden der Drift, denn Drift erzeugt einen Fehlwert oder einen lauten Fehler, **keinen Datenverlust** — die Datenverlust-Klassen hängen am Schnappschuss (ADR 0018), nicht an der Katalog-Frische.
- **Fail-open mit Warnung**: schreiben lassen und Drift nur melden. Verworfen, weil die einzige Fehlerklasse, die der Katalog überhaupt verhindert, dann genau in dem Moment ausfällt, in dem sie gebraucht wird.
- **Vom Plugin getrennte Katalog-Auslieferung** (Katalog als nachladbare Datendatei): verworfen. Sie verspricht schnellere Anpassung, erzeugt aber einen zweiten Auslieferungspfad und die Frage, welche Katalogfassung zu welchem Plugincode gehört. Katalog-Update = Plugin-Release.
- **Cron-gestützte Prüfung**: verworfen zugunsten „bei erkanntem Versionswechsel plus jederzeit abrufbar". Ein Cronjob prüft dauernd etwas, das sich nur beim Upgrade ändert.

## Consequences

- Zwei Prüftiefen: ein Billigteil bei **jedem** Schreibvorgang (Moodle-Versionsstring gegen den erklärten Geltungsbereich) und die Tiefenprüfung bei Versionswechsel. Kein Cron.
- Maschinell prüfbar sind Spalten (dazu/weg/umbenannt), die Existenz der zwölf aufrufbaren Quellen und die Existenz der Konstanten. **Nicht** prüfbar sind die abgeschriebenen Wertelisten, die Kombinationsregeln und die Nebenwirkungsvermerke — für sie ist das manuelle Review je Major-Release Pflicht, nicht Kür.
- Geltungsbereich je Modultyp und pro Major-Version. Ein Drift in `forum` lässt die anderen sieben schreibbar.
- Sichtbar über die Core-Check-API (`local_kurspilot_status_checks()`) in der Admin-Statusprüfung mit drei Zuständen je Modultyp: „geprüft / automatisch geprüft / braucht Arbeit". Die Lehrkraft sieht nie die Pflege, nur die Folge: „Diese Aktivitätsart kann ich gerade nicht ändern — bitte der Administration melden."
- Das Regime gilt für die Einzelwerkzeuge mit eigener Feldkenntnis (`create_quiz`, `update_quiz_settings`) unverändert.
- Restrisiko, benannt: zwischen Major-Upgrade und Katalog-Release können nur die nicht prüfbaren Kategorien driften, vor allem Auswahllisten. Konstantenwerte sind über die vorhandenen DB-Daten de facto eingefroren.
