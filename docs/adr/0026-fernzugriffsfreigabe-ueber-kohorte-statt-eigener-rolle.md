# Fernzugriffsfreigabe über gewählte Kohorten statt eigener Systemrolle

Kurslehrkräfte brauchen für den Fernzugriff eine Erlaubnis im Systemkontext, die ihnen ihre Kursrolle nicht gibt. Wir erteilen sie über eine Plugin-Einstellung, in der die Administration bestehende Systemkohorten auswählt: Wer Mitglied einer gewählten Kohorte ist, ist freigegeben. Daneben bleibt `local/coursepilot:useremote` als zweiter Weg, den die Schule in einer ohnehin systemweit vergebenen Rolle erlauben kann. Coursepilot legt weder Kohorte noch Rolle an, und die Archetyp-Vorbelegung für `editingteacher`/`teacher` entfällt, damit eine systemweite Lehrkraftrolle den Fernzugriff nicht nebenbei mitbringt ([#579](https://github.com/matthiasgruenwald/moodle-coursepilot/issues/579)).

## Considered Options

- **Dedizierte schmale Systemrolle** (Stand nach #575): hält das Moodle-Rechtemodell als einzige Wahrheit, verlangt aber eine eigene Rolle, die dauerhaft im Systemkontext liegt und je Person zugewiesen wird. Schulen pflegen Lehrkräfte in Kohorten, nicht in Systemrollen – verworfen.
- **Kohorten-Sync auf eine Rolle**: zentrale Pflege über die Kohorte, aber wieder mit eigener Rolle – verworfen aus demselben Grund.
- **Kategorie-Kohorten wählbar**: Kategorie-Manager könnten sich selbst Fernzugriff geben – nur Systemkohorten.

## Consequences

- Die Kohortenfreigabe ist eine zweite Rechtequelle neben `has_capability`. Moodles „Rechte prüfen“ zeigt sie nicht. Ausgleich: Die Fehlermeldung nennt beide Wege, die Einstellungsseite zeigt die Mitgliederzahl je gewählter Kohorte.
- Die Prüfung läuft bei jedem Aufruf; Entzug wirkt ohne Token-Löschung auch auf bestehende Verbindungen.
