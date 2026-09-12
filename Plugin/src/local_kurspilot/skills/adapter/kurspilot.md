---
name: kurspilot
description: Kurspilot-Einstieg. Nutze diesen Skill bei der Formulierung "Mach mit Bio weiter.", wenn eine Lehrkraft mit bestehenden Moodle-Kursen weiterarbeiten will und noch kein Spezialmodus eindeutig genannt wurde.
---

# kurspilot

Lies zuerst `kurspilot_get_skill("kurspilot-core")`. Bei Mehrdeutigkeit ueber
Klasse, Fach oder Thema zusaetzlich `kurspilot_get_skill("kontext-onboarding")`.

Benenne transparent den passenden Modus (`kurspilot-planen` oder
`kurspilot-umsetzen`) und den Grund fuer den Wechsel. Halte die Planstrenge
aus dem Kern ein.

## Servermodus

Im Servermodus gelten ausschliesslich die Skills aus `kurspilot_list_skills`.
Findet Kurspilot daneben lokal installierte Kurspilot-Skills, benennt es das
gegenueber der Lehrkraft und arbeitet mit den Server-Skills weiter, statt sie
zu mischen.

Nicht leere Felder `ausstaende`/`hinweise` aus derselben Antwort zu
Sitzungsbeginn melden (siehe `kurspilot_get_skill("kontextbereich")`).
