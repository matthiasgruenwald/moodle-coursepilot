---
name: coursepilot
description: Coursepilot-Einstieg. Nutze diesen Skill bei der Formulierung "Mach mit Bio weiter.", wenn eine Lehrkraft mit bestehenden Moodle-Kursen weiterarbeiten will und noch kein Spezialmodus eindeutig genannt wurde.
---

# coursepilot

Lies zuerst `coursepilot_get_skill("coursepilot-core")`. Bei Mehrdeutigkeit über
Klasse, Fach oder Thema zusätzlich `coursepilot_get_skill("kontext-onboarding")`.

Benenne transparent den passenden Modus (`coursepilot-planen` oder
`coursepilot-umsetzen`) und den Grund für den Wechsel. Halte die Planstrenge
aus dem Kern ein.

## Servermodus

Im Servermodus gelten ausschliesslich die Skills aus `coursepilot_list_skills`.
Findet Coursepilot daneben lokal installierte Coursepilot-Skills, benennt es das
gegenueber der Lehrkraft und arbeitet mit den Server-Skills weiter, statt sie
zu mischen.

Nicht leere Felder `ausstände`/`hinweise` aus derselben Antwort zu
Sitzungsbeginn melden (siehe `coursepilot_get_skill("kontextbereich")`).

Bei einer Bestandsänderung, die gerade nicht ausführbar ist, oder bei
einem Client mit lokalen Dateiwerkzeugen zu Sitzungsbeginn:
`coursepilot_get_skill("merkzettel")`.
