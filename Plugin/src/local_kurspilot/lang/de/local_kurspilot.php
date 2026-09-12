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

/**
 * Deutsche Strings.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Kurspilot';
$string['kurspilot:use'] = 'Kurspilot in einem Kurs nutzen';
$string['kurspilot:useremote'] = 'KI-Chat mit Kurspilot verbinden (Fernzugriff)';
$string['kurspilot:viewhistory'] = 'Änderungsverlauf von Aktivitäten einsehen';
$string['kurspilot:restoreversion'] = 'Aktivitäten auf eine frühere Version zurückschreiben';
$string['capabilitymissing'] = 'CAPABILITY_MISSING:{$a}';

// history.php: Verlaufsseite an der Kursnavigation (#397, Spec 0015 §10.6/§10.7).
$string['historynavnode'] = 'Kurspilot: Änderungsverlauf';
$string['historytitle'] = 'Änderungsverlauf';
$string['historyintro'] = 'Hier sehen Sie den erfassten Änderungsverlauf und können auf eine frühere Version zurückschreiben - unabhängig davon, ob gerade ein Kurspilot-Chat läuft.';
$string['historynoactivities'] = 'Keine Aktivität in diesem Kurs hat einen erfassten Änderungsverlauf.';
$string['historycolversion'] = 'Version';
$string['historycoluser'] = 'Nutzer';
$string['historycoltime'] = 'Zeitpunkt';
$string['historycolchange'] = 'Änderung';
$string['historycolname'] = 'Aktivität';
$string['historycoltype'] = 'Typ';
$string['historyview'] = 'Verlauf ansehen';
$string['historyrestore'] = 'Zurückschreiben';
$string['historyrestoreconfirm'] = 'Diese Aktivität wirklich auf Version {$a} zurückschreiben? Der alte Stand wird als neue jüngste Version fortgeschrieben, es entsteht keine zusätzliche Aktivität.';
$string['historydatalossconfirm'] = '{$a} Wirklich fortsetzen und dabei bestehende Abschlussdaten löschen?';
$string['historyquizhint'] = 'Hinweis: Fragen erscheinen bei Tests in der jeweils neuesten Fassung, keine Version wird nachträglich gepinnt.';
$string['historybacktolist'] = 'Zurück zur Aktivitätenliste';

// Fernzugriffs-Steuerung (#338).
$string['remoteaccessdisabled'] = 'Der Fernzugriff ist durch die Administration vorübergehend gesperrt.';
$string['settingremoteaccessenabled'] = 'Fernzugriff erlauben';
$string['settingremoteaccessenabled_desc'] = 'Notbremse: sperrt sofort jeden weiteren Zugriff über den MCP-Endpunkt. Bereits ausgestellte Zugriffstoken bleiben dabei gültig — für den Sicherheitsvorfall zusätzlich den Sammelwiderruf auf der Verbindungsübersicht nutzen. Der normale Moodle-Login ist von dieser Einstellung nicht betroffen.';
// Protokollierung (#339).
$string['settingloglevel'] = 'Protokollstufe';
$string['settingloglevel_desc'] = 'Steuert, welche Kurspilot-Zugriffe über die Moodle-Ereignis-API protokolliert werden und damit in den gewohnten Protokollberichten erscheinen.';
$string['loglevelnone'] = 'Kein Protokoll';
$string['loglevelerrors'] = 'Schreibzugriffe und Fehler';
$string['loglevelreads'] = 'Zusätzlich Lesezugriffe';
$string['loglevelall'] = 'Alles';
$string['event_tool_access_succeeded'] = 'Kurspilot-Werkzeugaufruf erfolgreich';
$string['event_tool_access_failed'] = 'Kurspilot-Zugriff fehlgeschlagen';

// Kontextbereich (#297, Issue #343).
$string['settingcontextroot'] = 'Wurzelordner des Kontextbereichs';
$string['settingcontextroot_desc'] = 'Rein organisatorisch, keine Sicherheitsgrenze. Aendern wirkt sich erst auf neu angelegte Dateien aus. Gilt nur für Lehrkräfte ohne eigenen Kontextpointer — dieser Ordner ist zugleich der feste Anker, in dem ein von Hand abgelegter Kontextpointer gesucht wird.';
$string['invalidcontextpath'] = 'Ungültiger Pfad.';
$string['contextfilenotfound'] = 'Datei nicht gefunden: {$a}';
$string['contextfilelocked'] = 'Datei gesperrt: {$a} — personenbezogen markiert (personenbezug: true), der Schalter für personenbezogene Kontextdaten ist ausgeschaltet.';

// Kontextpointer (Issue #445, Spec: Ablageort als eine Sache #442 §2).
$string['pointerunreadable'] = 'Kontextpointer nicht lesbar: {$a} enthält kein gültiges JSON-Objekt.';
$string['pointerincomplete'] = 'Kontextpointer unvollständig: {$a} muss die Felder "kontextbereich" und "materialordner" enthalten.';
$string['pointerunreachable'] = 'Kontextpointer verweist auf einen nicht erreichbaren Ort: {$a} enthält einen ungültigen Pfad.';
$string['materialbestandimkontext'] = 'Der Materialbestand liegt im Kontextbereich oder im selben Ordner — das ist nicht zulässig. Bitte auf der Ortswahlseite einen anderen Ordner für den Materialbestand oder den Kontextbereich wählen.';

// Kontextpointer, zweite Fassung: externe Orte (Issue #490, Spec: Kontextbereich
// und Materialbestand im WebDAV-Speicher der Lehrkraft #486 §2/§3/§12). Keine
// Meldung nennt Server, Pfad, Konto oder Passwort — der Speicherort bleibt
// verborgen (Spec §15, Geheimnis-Test).
$string['pointerexternalnotsupported'] = 'Dieser Bereich liegt extern — dieser Vorgang unterstützt externe Orte noch nicht. Bitte auf der Ortswahlseite ({$a}) auf "in Moodle" umstellen, oder abwarten, bis die externe Schreibunterstützung folgt.';
$string['webdavinstancemissing'] = 'Der Kontextpointer verweist auf eine WebDAV-Verbindung, die nicht mehr existiert. Bitte auf der Ortswahlseite ({$a}) neu einrichten.';
$string['webdavinstanceforeign'] = 'Der Kontextpointer verweist auf eine WebDAV-Verbindung, die nicht Ihnen gehört, oder Sie sind über "als Nutzer/in anmelden" angemeldet. Bitte auf der Ortswahlseite ({$a}) neu einrichten.';
$string['webdavnotenabled'] = 'Ihre Schule hat externe Speicher (WebDAV) für Sie noch nicht freigeschaltet. Bitte auf der Ortswahlseite ({$a}) nachsehen.';
$string['webdavauthunsupported'] = 'Diese WebDAV-Verbindung nutzt nicht mehr https mit Basic-Anmeldung — das unterstützt Kurspilot nicht. Bitte auf der Ortswahlseite ({$a}) korrigieren.';
$string['webdavfingerprintchanged'] = 'Server, Basispfad oder Konto dieser WebDAV-Verbindung haben sich geändert. Bitte auf der Ortswahlseite ({$a}) neu wählen.';
$string['webdaviservfilesonly'] = 'Bei IServ ist nur unterhalb von „Files/“ wählbar. Bitte auf der Ortswahlseite ({$a}) einen Ordner dort wählen.';
$string['webdavexternalerror'] = 'Der externe Speicher konnte nicht gelesen werden ({$a->errorclass}). Bitte später erneut versuchen oder auf der Ortswahlseite ({$a->page}) nachsehen.';
$string['webdavstep1instruction'] = 'Die Administration muss den Repository-Typ "WebDAV" in Website-Administration ▸ Plugins ▸ Repositories aktivieren.';
$string['webdavstep2instruction'] = 'Die Administration muss beim Repository-Typ "WebDAV" die Option "Nutzerinstanzen erlauben" einschalten.';
$string['webdavstep3instruction'] = 'Die Administration muss der Lehrkraft das Recht "repository/webdav:view" im eigenen Nutzerkontext zuweisen (empfohlen über eine eigene Systemrolle).';

// Ortswahlseite (Issue #494, Spec #486 §5/§10).
$string['ortswahl'] = 'Ortswahl';
$string['ortswahltitle'] = 'Wo Kontextbereich und Materialbestand liegen';
$string['ortswahlheading'] = 'Wo Kontextbereich und Materialbestand liegen';
$string['ortswahlintro'] = 'Wählen Sie je Ziel einen Ordner in einer Ihrer WebDAV-Verbindungen, oder lassen Sie es in Moodle.';
$string['ortswahltabkontextbereich'] = 'Kontextbereich';
$string['ortswahltabmaterialbestand'] = 'Materialbestand';
$string['ortswahlkontexthint'] = 'Empfehlung: ein eigener Ordner nur für Kurspilot — nicht mitten in bereits genutzten Unterlagen.';
$string['ortswahlkeepmoodle'] = 'In Moodle lassen';
$string['ortswahlchooseinstance'] = 'Verbindung wählen';
$string['ortswahlselectfolder'] = 'Diesen Ordner wählen';
$string['ortswahlbreadcrumbroot'] = 'Wurzel';
$string['ortswahlloading'] = 'Wird geladen …';
$string['ortswahlcreatefolder'] = 'Ordner anlegen';
$string['ortswahlnewfoldername'] = 'Name des neuen Ordners';
$string['ortswahlprogresschosen'] = '{$a}';
$string['ortswahlprogressopen'] = 'noch offen: {$a}';
$string['ortswahlfinishbutton'] = 'Einrichten abschließen';
$string['ortswahlfinishsuccess'] = 'Gespeichert. Geänderte Ziele: {$a}.';
$string['ortswahlfinishnochange'] = 'Keine Änderung — der bisherige Ort bleibt bestehen.';
$string['ortswahlselectionincomplete'] = 'Bitte für jedes Ziel eine Antwort wählen, bevor Sie abschließen.';
$string['ortswahlselectioninvalid'] = 'Ungültige Auswahl — bitte den Ordner im Dateifenster erneut wählen.';
$string['ortswahltimeouttitle'] = 'Keine Antwort';
$string['ortswahltimeouttext'] = 'Der Speicher antwortet nicht innerhalb von 8 Sekunden. Nichts wurde gespeichert.';
$string['ortswahlretry'] = 'Erneut versuchen';
$string['ortswahlcheckcredentials'] = 'Zugangsdaten prüfen';
$string['ortswahllater'] = 'Später';
$string['ortswahlcurrentheading'] = 'Aktueller Ort';
$string['ortswahlcurrentkontextbereich'] = 'Kontextbereich: {$a}';
$string['ortswahlcurrentmaterialbestand'] = 'Materialbestand: {$a}';
$string['ortswahlhistoryheading'] = 'Bisherige Orte';
$string['ortswahlhistoryempty'] = 'Noch keine Änderungen.';
$string['ortswahlhistorydate'] = 'Datum';
$string['ortswahlhistorytarget'] = 'Ziel';
$string['ortswahlhistoryfrom'] = 'Von';
$string['ortswahlhistoryto'] = 'Nach';
$string['ortswahllocationmoodle'] = 'in Moodle ({$a})';
$string['ortswahllocationextern'] = '{$a->instance} / {$a->path}';
$string['ortswahllocationexternroot'] = '{$a} (Wurzel)';
$string['ortswahlinstanceunknown'] = 'unbekannte Verbindung';
$string['ortswahlnoinstanceheading'] = 'Noch keine eigene WebDAV-Verbindung';
$string['ortswahlnoinstanceintro'] = 'Ihre Schule hat externe Speicher freigeschaltet, Sie haben aber noch keine eigene Verbindung eingerichtet:';
$string['ortswahlnoinstancestep1'] = '1. Öffnen Sie den Dateipicker (z. B. beim Hochladen einer Datei), wählen Sie "WebDAV" und dann "Repository konfigurieren".';
$string['ortswahlnoinstancestep2'] = '2. Tragen Sie Server, Pfad und Ihre Zugangsdaten ein und speichern Sie.';
$string['ortswahlnoinstancestep3'] = '3. Kehren Sie auf diese Seite zurück — die neue Verbindung erscheint hier automatisch.';
$string['ortswahlschoolhintheading'] = 'Hinweis Ihrer Schule';
$string['ortswahlnotenabledheading'] = 'Externe Speicher noch nicht freigegeben';
$string['ortswahlnotenabledtext'] = 'Ihre Schule hat externe Speicher noch nicht freigegeben. Bis dahin liegt alles in Moodle.';
$string['ortswahlmissingstepsheading'] = 'Text für die Administration';
$string['ortswahlmissingstepsintro'] = 'Kopieren Sie den Text und schicken Sie ihn an Ihre Moodle-Administration:';
$string['ortswahlcoresupportlink'] = 'Support-Kontakt Ihrer Moodle-Instanz';

// Sperren und Uebergabe (Issue #497, Spec #486 §5).
$string['ortswahlrootnotselectable'] = 'Die Wurzel dieser Verbindung ist nicht wählbar — bitte einen Ordner darunter wählen.';
$string['ortswahliservfilesonly'] = 'Bei IServ ist nur unterhalb von „Files/“ wählbar.';
$string['ortswahlinstanceauthunsupported'] = 'Diese Verbindung nutzt kein https mit Basic-Anmeldung und ist deshalb nicht wählbar.';
$string['ortswahloverlaplocked'] = 'Der Materialbestand liegt im Kontextbereich oder im selben Ordner — bitte einen anderen Ordner wählen.';
$string['ortswahlconfirmheading'] = 'Ordner ist nicht leer';
$string['ortswahlconfirmcount'] = '{$a} Einträge liegen hier bereits, unter anderem:';
$string['ortswahlconfirmtext'] = 'Kurspilot legt hier Markdown-Dateien an und kann gleichnamige Markdown-Dateien überschreiben. Löschen oder verschieben kann es nichts.';
$string['ortswahlconfirmbutton'] = 'Das ist mein Kurspilot-Ordner';
$string['ortswahlconfirmcancel'] = 'Abbrechen';
$string['settingwebdavhint'] = 'Hinweis der Schule (Ortswahl)';
$string['settingwebdavhint_desc'] = 'Optionaler Freitext, der Lehrkräften ohne eigene WebDAV-Verbindung auf der Ortswahlseite zusätzlich zu den drei Einrichtungsschritten angezeigt wird — z. B. eine Empfehlung, welchen Cloud-Dienst die Schule stellt.';
$string['listskillsortswahlhint'] = 'Die Lehrkraft kann Kontextbereich und Materialbestand statt in Moodle in einem eigenen WebDAV-Speicher ablegen — Ortswahl unter {$a}.';

// Altbestand (Issue #498, Spec #486 §9/§10): der vorherige Ort nach einem
// Ortswechsel des Kontextbereichs — nur lesbar, endet ausdrücklich.
$string['ortswahlaltbestandopen'] = 'Vom früheren Ort ist noch nicht alles übernommen.';
$string['listskillsaltbestandhint'] = 'Am vorherigen Ort des Kontextbereichs liegen noch Kontextdateien (Altbestand). Anbieten, sie zu kopieren — Ortswahl unter {$a}.';
$string['altbestandclosed'] = 'Es liegt kein offener Altbestand vor.';
$string['altbestanddismissed'] = 'Altbestand abgeschlossen — der vorherige Ort wird nicht mehr erwähnt.';
$string['contextfilealreadyexists'] = '{$a} existiert am neuen Ort bereits — nicht überschrieben (Kopieren legt nur an, nie überschreibend).';

// Ausstandsnotiz (Issue #492, ADR 0023, Spec #486 §8/§10): kein absoluter
// Serverpfad, kein Benutzername, kein Passwort, kein HTTP-Code, kein
// Antwortrumpf (Geheimnis-Test) — der Rohcode geht ins Zugriffsprotokoll.
$string['ausstandwritefailed'] = '{$a->path} ({$a->operation}): {$a->reason}. Noch nicht gespeichert, vermerkt (Kennung {$a->kennung}). Bitte den Inhalt im Gespräch behalten und denselben Aufruf mit ausstand="{$a->kennung}" wiederholen, sobald die Verbindung wieder steht. Verbindung: {$a->target}.';
$string['ausstandnotewritefailed'] = '{$a->path} ({$a->operation}) nicht geschrieben, und die Ausstandsnotiz konnte ebenfalls nicht angelegt werden — Ihre Private Files sind voll. Bitte Platz schaffen und erneut versuchen, sonst geht der Inhalt verloren.';
$string['ausstandnotequotaexceeded'] = 'Die Ausstandsnotiz konnte nicht geschrieben werden — der Speicherplatz in Ihren Private Files reicht nicht.';
$string['ausstandunknown'] = 'Kein offener Ausstand mit der Kennung {$a}.';
$string['ausstanddismissed'] = 'Ausstand {$a} verworfen.';

// Schreiben in den Kontextbereich (#408, Spec 0016 §4.1).
$string['contextfilenotmarkdown'] = 'In den Kontextbereich lassen sich nur .md-Dateien schreiben: {$a}';
$string['contextfiletoolarge'] = 'Inhalt zu groß: {$a->size} Byte, erlaubt sind höchstens {$a->max} Byte je Schreibvorgang.';
$string['contextfilechanged'] = 'Nicht geschrieben: {$a} wurde seit dem letzten Lesen geändert — bitte die Datei neu lesen und den Vorgang wiederholen.';
$string['contextfileexternalconflict'] = 'Konflikt: {$a} wurde extern zwischenzeitlich geändert — bitte neu lesen, die Änderungen zusammenführen und erneut schreiben.';
$string['contextquotaexceeded'] = 'Nicht geschrieben: der Speicherplatz reicht nicht — benötigt {$a->needed} MB, frei sind noch {$a->remaining} MB. Siehe Ortswahlseite ({$a->page}).';
$string['contextfilecreated'] = '{$a} neu angelegt.';
$string['contextfileoverwritten'] = '{$a->path} überschrieben (vorher: {$a->before} Byte, jetzt: {$a->after} Byte).';
$string['contextfileappended'] = '{$a->path} angehängt (jetzt: {$a->size} Byte insgesamt).';
$string['contextfilerotation'] = 'Die Datei überschreitet 1 MB — Rotation empfohlen.';

// Materialordner (Spec 0018 §2, Issue #428).
$string['settingmaterialroot'] = 'Wurzelordner des Materialordners';
$string['settingmaterialroot_desc'] = 'Rein organisatorisch, keine Sicherheitsgrenze. Aendern wirkt sich erst auf neu angelegte Dateien aus. Gilt nur für Lehrkräfte ohne eigenen Kontextpointer.';
$string['invalidmaterialpath'] = 'Ungültiger Pfad.';
$string['materialfiledisallowedtype'] = 'Dateityp nicht zulässig: {$a->filename} — erlaubt sind: {$a->allowed}.';
$string['materialfilechanged'] = 'Nicht geschrieben: {$a} wurde seit dem letzten Lesen geändert — bitte die Datei neu lesen und den Vorgang wiederholen.';
$string['materialfiletoolarge'] = 'Datei zu groß: {$a->size} Byte, der Server erlaubt höchstens {$a->max} Byte je Upload (post_max_size/upload_max_filesize).';
$string['materialembedtoolarge'] = 'Datei zu groß für die Einbettung: {$a->size} Byte, erlaubt sind höchstens {$a->max} Byte je Datei ($CFG->maxbytes).';
$string['materialquotaexceeded'] = 'Nicht geschrieben: der Speicherplatz reicht nicht — benötigt {$a->needed} MB, frei sind noch {$a->remaining} MB.';
$string['materialquotawarning'] = 'Hinweis: nur noch {$a} MB Speicherplatz frei.';
$string['materialfilecreated'] = '{$a} neu angelegt.';
$string['materialfileoverwritten'] = '{$a->path} überschrieben (vorher: {$a->before} Byte, jetzt: {$a->after} Byte).';
$string['materialfilenotfound'] = 'Keine Materialdatei unter "{$a}" gefunden — erwarteter Pfad im Materialordner. Erst mit upload_material_file ablegen, dann verweisen.';
$string['invalidmaterialort'] = 'Unbekannter Ort "{$a}" — gültig sind "bestand" und "werkbank".';
$string['materialpathiskontext'] = 'Dieser Pfad gehört zum Kontextbereich, nicht zum Materialbestand — über die Materialwerkzeuge nicht erreichbar. Bitte stattdessen list_context_files/read_context_file nutzen.';
$string['materialgdmissing'] = 'Bildvorschau und Bildzuschnitt sind auf diesem Server gesperrt — die PHP-Erweiterung GD fehlt. Hochladen und Einbetten funktionieren weiterhin.';
$string['materialpreviewnotanimage'] = '"{$a}" ist keine Bilddatei — dafür gibt es keine Vorschau.';
$string['materialpreviewunsupported'] = 'Diese Datei lässt sich nicht als Bild lesen (z. B. SVG oder beschädigte Bilddaten) — dafür gibt es keine Vorschau.';
$string['invalidmaterialreferencelist'] = 'Feld "{$a}" erwartet eine Liste von Materialordner-Pfaden (JSON-Array), z.B. ["arbeitsblatt.pdf"].';
$string['folderfilespatchunsupported'] = 'Dateien lassen sich einem bestehenden "folder" nicht nachträglich per update_module_settings hinzufügen (Moodle-Eigenheit von folder_update_instance()). Legen Sie den Ordner stattdessen mit create_module und dem Feld "files" an, oder erstellen Sie einen weiteren folder für die zusätzlichen Dateien.';

// Bildzuschnitt (Spec 0018 §5, Issue #431).
$string['materialcropsourceunsupported'] = '"{$a}" lässt sich nicht zuschneiden — GD ist raster-only, SVG und beschädigte Bilddaten sind ausgeschlossen.';
$string['materialcropoutputunsupported'] = 'Zielendung "{$a}" kann kein Zuschnittergebnis sein — erlaubt sind: png, jpg, jpeg, gif, webp.';
$string['materialcropinvalidcoordinates'] = 'Ungültiger Ausschnitt: Koordinaten müssen zwischen 0 und 1 liegen und eine Fläche größer 0 ergeben (x0={$a->x0}, y0={$a->y0}, x1={$a->x1}, y1={$a->y1}).';
$string['materialcropcreated'] = '{$a->path} zugeschnitten aus {$a->source} angelegt ({$a->width}×{$a->height}px).';
$string['materialcropoverwritten'] = '{$a->path} zugeschnitten aus {$a->source} überschrieben ({$a->width}×{$a->height}px).';

// Aufräumen: lose Materialdateien (Spec 0018 §8.2/§8.3, Issue #438).
$string['materialfilesdeleted'] = '{$a->count} Datei(en) gelöscht, {$a->freed} MB freigeworden.';
$string['materialdeletefilenotfound'] = 'Nicht gelöscht: keine Materialdatei unter "{$a}" gefunden — bitte die Pfadliste prüfen (Tippfehler?).';

// Schalter für personenbezogene Kontextdaten (#344, ADR 0011).
$string['settingallowpersonaldata'] = 'Personenbezogene Kontextdaten übertragen';
$string['settingallowpersonaldata_desc'] = 'Wirkt auf der Markierung (Frontmatter „personenbezug: true"), nicht auf dem Inhalt. Solange aus, sind so markierte Kontextdateien für kein Lese-Werkzeug lesbar und erscheinen in Listen als gesperrt statt weggelassen. Standard: aus.';

// Zugelassene externe Speicher für personenbezogene Kontextdaten (#493, ADR 0021 §3).
$string['settingpersonaldatahosts'] = 'Zugelassene Speicher für personenbezogene Daten';
$string['settingpersonaldatahosts_desc'] = 'Eine Datei mit Markierung „personenbezug: true" wird nur in einen dieser Speicher geschrieben (Private Files sind immer zugelassen). Ein Eintrag je Zeile: eine Domain gilt samt Unterdomains, getrennt wird nur an Punkten, ohne „*". Einträge mit nur einem Namensteil werden beim Speichern abgelehnt. Leere Liste = nur Private Files.';
$string['personaldatahostsinvalid'] = 'Ungültiger Eintrag: „{$a}" — ein Eintrag muss eine Domain mit mindestens zwei Namensteilen sein (z. B. „cloud.beispielschule.de"), ohne „*".';
$string['contextfilehostnotallowed'] = 'Datei {$a}: Dieser Speicher ist für personenbezogene Daten nicht zugelassen.';

// Aenderungsverlauf: Aufbewahrung/Loeschfrist (#387).
$string['settinghistoryretentiondays'] = 'Aufbewahrungsfrist des Aenderungsverlaufs (Tage)';
$string['settinghistoryretentiondays_desc'] = 'Wie lange Staende des Aenderungsverlaufs je Aktivitaet aufbewahrt werden, bevor sie beim naechsten Schreibvorgang derselben Aktivitaet geloescht werden. Kein Cron noetig - die Bereinigung laeuft mit jedem Schreibvorgang mit. Mindestens 1 Tag; „keine Frist" ist ausgeschlossen.';

$string['connections'] = 'Kurspilot-Verbindungen';
$string['connectionsintro'] = 'Alle aktiven Fernzugriffsverbindungen dieser Instanz. Ein Widerruf entwertet das zugehörige Token sofort — ein weiterer Zugriff damit schlägt danach fehl.';
$string['myconnections'] = 'Meine Kurspilot-Verbindungen';
$string['myconnectionsintro'] = 'KI-Anwendungen, die Sie mit Kurspilot verbunden haben. Ein Widerruf entwertet die Verbindung sofort.';
$string['connectionnoconnections'] = 'Keine aktiven Verbindungen.';
$string['connectionclient'] = 'Anwendung';
$string['connectionperson'] = 'Person';
$string['connectionsince'] = 'Verbunden seit';
$string['connectionexpires'] = 'Zugriffstoken gültig bis';
$string['connectionrevoke'] = 'Widerrufen';
$string['connectionrevokeall'] = 'Alle Verbindungen widerrufen';
$string['connectionrevokeallconfirm'] = 'Wirklich alle ausgestellten Zugriffs- und Erneuerungstoken entwerten? Jede Verbindung muss danach neu hergestellt werden.';

// surface.php.
$string['surface'] = 'Kurspilot-Datenoberfläche';
$string['surfaceintro'] = 'Kurspilot gibt ausschließlich lehrkraftbezogene Kursgestaltung frei. Diese Seite zeigt die vereinbarte Oberfläche und gleicht sie mit der auf dieser Instanz tatsächlich registrierten ab.';
$string['surfaceallowed'] = 'Freigegebene Werkzeuge';
$string['surfaceforbidden'] = 'Verbotene Namensbestandteile';
$string['surfaceregistered'] = 'Tatsächlich registrierte Webservice-Funktionen';
$string['surfacestatus'] = 'Abgleichstatus';
$string['surfaceok'] = 'Die registrierte Oberfläche entspricht dem Vertrag.';
$string['surfaceviolations'] = 'Die registrierte Oberfläche verletzt den Vertrag:';

// surface.php: Instanzprüfung per Selbstabruf (#340).
$string['surfaceinstance'] = 'Instanzvoraussetzungen für den Fernzugriff';
$string['surfaceinstanceintro'] = 'Damit KI-Werkzeuge diese Instanz erreichen, braucht es öffentliches HTTPS, Egress zum Anbieter und funktionierendes PATH_INFO. Diese Seite prüft das nicht per Konfigurationsblick, sondern per echtem Selbstabruf der Discovery-Adresse – Reverse-Proxies und abgeschaltetes PATH_INFO fallen sonst erst beim ersten Verbindungsversuch eines Clients auf.';
$string['surfacereqhttps'] = 'Öffentliches HTTPS';
$string['surfacereqegress'] = 'Egress zum Anbieter (ausgehende Verbindungen erlaubt)';
$string['surfacereqpathinfo'] = 'PATH_INFO wird an PHP-Dateien durchgereicht';
$string['selfcheckurl'] = 'Geprüfte Adresse: {$a}';
$string['selfcheckok'] = 'Selbstabruf erfolgreich – die Discovery-Adresse ist von dieser Instanz aus erreichbar.';
$string['selfcheckrequireshttps'] = 'Selbstabruf fehlgeschlagen – die Instanz ist nicht über HTTPS erreichbar (öffentliches HTTPS ist Voraussetzung).';
$string['selfcheckrequestfailed'] = 'Selbstabruf fehlgeschlagen – kein Antwort vom Server erhalten (Timeout, DNS oder TLS-Fehler).';
$string['selfcheckunexpectedstatus'] = 'Selbstabruf fehlgeschlagen – die Discovery-Adresse hat nicht mit HTTP 200 geantwortet.';
$string['selfcheckinvalidbody'] = 'Selbstabruf fehlgeschlagen – die Antwort enthält keine gültigen Discovery-Metadaten.';
$string['surfaceinstanceemergencyexit'] = 'Notausgangs-Regel als dokumentierte Abweichung: Ziel ist Null-Eingriff am Webserver, aber falls PATH_INFO nicht ankommt, hilft eine Webserver-Regel wie "{$a}" (Apache) im virtuellen Host dieser Instanz – kein Zielweg, sondern eine belegte Ausnahme.';

// oauth/authorize.php, oauth/token.php (#336).
$string['authorizetitle'] = 'Kurspilot verbinden';
$string['authorizetitleclient'] = 'Kurspilot mit {$a} verbinden';
$string['authorizeerror'] = 'Autorisierungsfehler: {$a}';
$string['consentconfirm'] = 'Verbindung erlauben';
$string['consentdeny'] = 'Ablehnen';
$string['consentintro'] = 'Sie erlauben <strong>{$a}</strong>, in Ihrem Namen auf Ihre Moodle-Kurse zuzugreifen. Es gelten Ihre eigenen Rechte — Sie sehen nichts, was Sie nicht ohnehin sehen dürfen.';
$string['consentgranted'] = '<strong>Freigegeben:</strong> Kursliste, Abschnitte, Aktivitäten und deren Inhalte (Seitentexte, Aufgabenstellungen, Fragen) sowie Ihre Kurspilot-Kontextdateien.';
$string['consentdenied'] = '<strong>Nicht freigegeben:</strong> Abgaben, Forenbeiträge, Testversuche, Bewertungen, Teilnehmendenlisten.';
$string['consenttransfer'] = '<strong>Übertragung an den KI-Anbieter:</strong> Alles, was Kurspilot auf Anfrage liest, wird an den KI-Anbieter übertragen und dort verarbeitet. Es läuft nichts im Hintergrund — übertragen wird nur, was ein Werkzeug auf Anfrage zurückgibt.';
$string['consentpersonaldataoff'] = 'Diese Moodle-Instanz überträgt <strong>keine</strong> Kontextdateien, die als personenbezogen markiert sind (personenbezug: true). Solche Dateien werden Ihnen als gesperrt angezeigt.';
$string['consentpersonaldataon'] = 'Diese Moodle-Instanz überträgt <strong>auch</strong> Kontextdateien, die als personenbezogen markiert sind (personenbezug: true) — etwa Lerngruppenprofile mit Schülernamen. Ihre Schule hat das ausdrücklich freigegeben.';
$string['consentabbreviate'] = 'Welche personenbezogenen Angaben Sie in Kontextdateien ablegen dürfen, richtet sich nach den Vorgaben Ihrer Schule und den Bestimmungen Ihres Landesdatenschutzes. Kurspilot prüft das nicht. Wo es für die Planung ausreicht, verwenden Sie Kürzel statt Namen.';
$string['consentrevoke'] = 'Sie können diese Verbindung jederzeit unter Profil → Meine Kurspilot-Verbindungen widerrufen.';

// Ortswahl beim Verbindungsaufbau (Issue #446, seit Issue #494 nur noch Anzeige).
$string['consentlocationheading'] = 'Wo Ihr Kurspilot-Bereich liegt';
$string['consentlocationintro'] = 'Kurspilot legt Ihre Journale, Pläne und Materialien an den unten genannten Orten ab.';
$string['consentlocationkontextbereichcurrent'] = 'Journale und Pläne: {$a}';
$string['consentlocationmaterialbestandcurrent'] = 'Materialdateien: {$a}';
$string['consentlocationchangelink'] = 'Ort ändern';

// classes/privacy/provider.php (#336).
$string['privacy:metadata:oauth_code'] = 'Kurzlebige, PKCE-gebundene Autorisierungscodes für den OAuth-Zustimmungsdialog.';
$string['privacy:metadata:oauth_code:clientid'] = 'Die Kennung des KI-Clients, für den der Code ausgestellt wurde.';
$string['privacy:metadata:oauth_code:userid'] = 'Die Nutzer-ID der Lehrkraft, die zugestimmt hat.';
$string['privacy:metadata:oauth_code:redirecturi'] = 'Das Umleitungsziel des Clients.';
$string['privacy:metadata:oauth_code:codechallenge'] = 'Die PKCE-S256-Challenge des Clients.';
$string['privacy:metadata:oauth_code:expires'] = 'Ablaufzeitpunkt des Codes.';
$string['privacy:metadata:oauth_code:used'] = 'Ob der Code bereits eingelöst wurde.';
$string['privacy:metadata:oauth_token'] = 'Zugriffs- und Erneuerungstoken, mit denen ein KI-Client im Namen der Lehrkraft auf Kurspilot zugreift.';
$string['privacy:metadata:oauth_token:clientid'] = 'Die Kennung des KI-Clients.';
$string['privacy:metadata:oauth_token:userid'] = 'Die Nutzer-ID der Lehrkraft, in deren Namen der Client zugreift.';
$string['privacy:metadata:oauth_token:expires'] = 'Ablaufzeitpunkt des Zugriffstokens.';
$string['privacy:metadata:oauth_token:refreshexpires'] = 'Ablaufzeitpunkt des Erneuerungstokens.';
$string['privacy:metadata:oauth_token:revoked'] = 'Ob das Token widerrufen bzw. durch Rotation entwertet wurde.';
$string['privacy:metadata:oauth_token:timecreated'] = 'Ausstellungszeitpunkt.';

// classes/privacy/provider.php: Kontextdateien (#343, #345).
$string['privacy:metadata:core_files'] = 'Kurspilot-Kontextdateien im privaten Dateibereich der Lehrkraft.';

// classes/privacy/provider.php: Aenderungsverlauf (#385/#386/#387).
$string['privacy:metadata:cm_version'] = 'Aenderungsverlauf von Aktivitaeten: je Schreibvorgang ein Vollstand der Einstellungen, mit der Nutzer-ID der Lehrkraft, die den Schreibvorgang ausgeloest hat. Wird spaetestens 1 Jahr nach dem Schreibvorgang automatisch geloescht (admin-seitig verkuerzbar, Einstellung "Aufbewahrungsfrist des Aenderungsverlaufs"), sowie sofort beim Loeschen der Aktivitaet oder des Kurses.';
$string['privacy:metadata:cm_version:cmid'] = 'Die Aktivitaet, zu der dieser Stand gehoert.';
$string['privacy:metadata:cm_version:courseid'] = 'Der Kurs, zu dem diese Aktivitaet zum Zeitpunkt des Schreibvorgangs gehoerte.';
$string['privacy:metadata:cm_version:userid'] = 'Die Nutzer-ID der Lehrkraft, unter der der Schreibvorgang lief.';
$string['privacy:metadata:cm_version:timecreated'] = 'Zeitpunkt des Schreibvorgangs.';
$string['privacy:metadata:cm_version_file'] = 'Verknuepfung eines Verlaufs-Standes mit den zu diesem Zeitpunkt vorhandenen Dateien der Aktivitaet (nur Metadaten, siehe local_kurspilot_cm_file). Faellt zusammen mit dem zugehoerigen Stand weg.';
$string['privacy:metadata:cm_file'] = 'Deduplizierte Datei-Metadaten (Name, Groesse, Pfad) des Aenderungsverlaufs, ohne Dateiinhalt.';
$string['privacy:metadata:context_mark'] = 'Markierungsgedaechtnis (#493): je Kontextdatei nur das Bit "markiert ja/nein" sowie der Schluessel zur Aenderungserkennung (Pfad, Groesse, Aenderungszeit, ETag) - nie Dateiinhalt.';
$string['privacy:metadata:context_mark:userid'] = 'Die Nutzer-ID der Lehrkraft, zu der dieser Eintrag gehoert.';
$string['privacy:metadata:context_mark:path'] = 'Client-Pfad der Kontextdatei, auf die sich dieser Eintrag bezieht.';
$string['privacy:metadata:context_mark:ismarked'] = 'Ob die Datei zuletzt als personenbezogen markiert erkannt wurde.';

// Feldkatalog (#379).
$string['unknownmodname'] = 'Unbekannte Aktivitätsart "{$a->modname}". Kurspilot führt: {$a->aktivitaetsarten}.';

// Skill-Korpus: kurspilot_list_skills/kurspilot_get_skill (Spec 0020 §4, #450).
$string['unknownskillname'] = 'Unbekannter Skill-Name "{$a->name}". Gültige Namen: {$a->namen}.';

// Schreibkern: update_module_settings (#388).
$string['writevehicleblocked'] = '"{$a->modname}" wird nicht über update_module_settings geschrieben, sondern über {$a->schreibweg}. Nichts wurde geschrieben.';
$string['invalidpatchjson'] = 'felder_json ist kein gültiges JSON-Objekt. Nichts wurde geschrieben.';
$string['invalideditorpseudofield'] = 'Das Feld "{$a->field}" braucht den Inhalt als Text oder als Objekt mit "text" - angegeben war {$a->value}. Ohne "text" bliebe der Inhalt leer, deshalb wurde nichts geschrieben.';
$string['unknownfield'] = 'Unbekanntes Feld "{$a->field}" für Aktivitätsart "{$a->modname}". describe_module_fields(modname: "{$a->modname}", vollstaendig: true) zeigt die erlaubten Felder. Nichts wurde geschrieben.';
$string['blockedfield'] = 'Feld "{$a->field}" ist für Aktivitätsart "{$a->modname}" gesperrt und kann nicht per Patch gesetzt werden. describe_module_fields(modname: "{$a->modname}", vollstaendig: true) zeigt die Sperrliste. Nichts wurde geschrieben.';
$string['invalidfieldvalue'] = 'Ungültiger Wert "{$a->value}" für Feld "{$a->field}" bei Aktivitätsart "{$a->modname}". describe_module_fields(modname: "{$a->modname}", vollstaendig: true) zeigt den Wertebereich. Nichts wurde geschrieben.';
$string['combinationruleviolation'] = 'Kombinationsregel verletzt für Aktivitätsart "{$a->modname}": {$a->message} describe_module_fields(modname: "{$a->modname}", vollstaendig: true) zeigt alle Kombinationsregeln. Nichts wurde geschrieben.';

// Sichtbarkeit/Stealth/Gruppenmodus über den gemeinsamen Block (#390).
$string['stealthnotallowed'] = 'Stealth ("visibleoncoursepage" = 0) ist auf dieser Moodle-Instanz abgeschaltet (Einstellung "allowstealth"). Die Aktivität kann verborgen (visible = 0) oder sichtbar geschaltet werden, aber nicht unsichtbar auf der Kursseite bei gleichzeitiger Erreichbarkeit. Nichts wurde geschrieben.';

// Schreibkern: create_module (#389).
$string['requiredfieldwithoutdefault'] = 'Diese Pflichtfelder für Aktivitätsart "{$a->modname}" haben keinen Formular-Default und müssen genannt werden: {$a->field}. Nichts wurde angelegt.';
$string['readonlyvocabularyfield'] = 'Das Feld "{$a->field}" ist Lese-Vokabular der Lese-Werkzeuge und kein Schreibfeld für Aktivitätsart "{$a->modname}". Zum Setzen: {$a->hint}. Nichts wurde geschrieben.';

// Schreibkern: Struktur und Positionen (#391).
$string['invalidsectionnum'] = 'Ungültige Abschnittsnummer "{$a->sectionnum}". Nichts wurde geschrieben.';
$string['sectionnotfound'] = 'Abschnitt "{$a->sectionnum}" existiert nicht.';
$string['sectionunknownfield'] = 'Unbekanntes Feld "{$a->field}" für Abschnitte. Erlaubt: {$a->felder}. Nichts wurde geschrieben.';
$string['sectioninvalidvisible'] = 'Ungültiger Wert "{$a->value}" für "visible" - erlaubt sind 0 oder 1. Nichts wurde geschrieben.';
$string['sectionnotmovable'] = 'Abschnitt "{$a->sectionnum}" existiert nicht oder ist der allgemeine Abschnitt (0) - dieser kann nicht verschoben werden.';

// Schreibkern: set_completion (#392).
$string['completionunknownfield'] = 'Unbekanntes Vervollständigungsfeld "{$a->field}". Erlaubt für diese Aktivitätsart: {$a->erlaubt}. Nichts wurde geschrieben.';
$string['completionfieldviasetcompletion'] = 'Das Vervollständigungsfeld "{$a->field}" wird nicht per Feld-Patch gesetzt, sondern ausschließlich über set_completion (cmid, felder_json) - Moodle verwirft es sonst still oder löscht die Abschlussdaten der Lernenden. Nichts wurde geschrieben.';
$string['completionfieldnotformodname'] = 'Das Vervollständigungsfeld "{$a->field}" gibt es nur bei den Aktivitätsarten {$a->modnames}, nicht bei "{$a->modname}". Nichts wurde geschrieben.';
$string['completioninvalidfieldvalue'] = 'Ungültiger Wert "{$a->value}" für Vervollständigungsfeld "{$a->field}". Nichts wurde geschrieben.';
$string['completionnotenabled'] = 'Die Abschlussverfolgung ist für diesen Kurs (oder die gesamte Moodle-Instanz) deaktiviert. Moodle würde diese Felder ohnehin still verwerfen. Aktivieren Sie zuerst die Abschlussverfolgung im Kurs. Nichts wurde geschrieben.';
$string['completiondatalossconfirmationrequired'] = 'Diese Änderung würde die vorhandenen Abschlussdaten von {$a->betroffene_lernende} Lernenden für diese Aktivität löschen - Moodle löscht und berechnet sie neu, sobald dieser Schreibvorgang die Vervollständigung entsperrt. Nichts wurde geschrieben. Rufen Sie set_completion erneut mit "bestaetigt": true auf, um trotzdem fortzufahren.';
$string['sectiontargetoutofrange'] = 'Zielposition "{$a->nach}" liegt außerhalb des gültigen Bereichs (1 bis {$a->max}).';

// Schreibkern: set_restriction (#393).
$string['restrictionsnotenabled'] = 'Bedingte Verfügbarkeit ist auf dieser Moodle-Instanz deaktiviert (Einstellung "enableavailability"). Moodle würde Voraussetzungen ohnehin verwerfen. Nichts wurde geschrieben.';
$string['invalidrestrictionjson'] = 'bedingungen_json ist kein gültiges JSON-Array von Bedingungsobjekten. Nichts wurde geschrieben.';
$string['restrictionunknowntype'] = 'Ungültiger Wert {$a->value} für "{$a->field}". Erlaubt: "abschluss", "datum", "gruppe". Nichts wurde geschrieben.';
$string['restrictionactivitynotfound'] = 'Ungültiger Wert {$a->value} für "{$a->field}": keine Aktivität mit dieser cmid im selben Kurs. Nichts wurde geschrieben.';
$string['restrictioninvalidstatus'] = 'Ungültiger Wert {$a->value} für "{$a->field}". Erlaubt: abgeschlossen, nicht_abgeschlossen, bestanden, nicht_bestanden. Nichts wurde geschrieben.';
$string['restrictioninvaliddate'] = 'Ungültige Datumsbedingung ({$a->value}). "richtung" muss "ab" oder "bis" sein, "zeitstempel" eine Unix-Zeit (Ganzzahl). Nichts wurde geschrieben.';
$string['restrictiongroupnotfound'] = 'Ungültiger Wert {$a->value} für "{$a->field}": keine Gruppe mit dieser ID im selben Kurs. Nichts wurde geschrieben.';

// Schreibkern: Aenderungsverlauf-Oberflaeche (#394).
$string['versionnotfound'] = 'Version {$a->version} existiert nicht für diese Aktivität (cmid {$a->cmid}).';

// Schreibkern: Quiz-Anordnungs-Stand im Aenderungsverlauf (#396).
$string['arrangementrestoreblocked'] = 'Die Fragenanordnung dieses Tests (quizid {$a->quizid}) kann nicht zurückgeschrieben werden: es gibt bereits Versuche. Ab jetzt ist die gespeicherte Anordnung nur noch Chronik, nicht mehr wiederherstellbar.';

// Spec 0017: Quiz-Anschluss (#420).
$string['addquestionstoquizblocked'] = 'Diesem Test (quizid {$a->quizid}) können keine Fragen mehr hinzugefügt werden: es gibt bereits Versuche. Nichts wurde geändert.';

// Schreibkern: Drift-Check und Admin-Statusprüfung (#399, ADR 0017).
$string['modnamedriftlocked'] = 'Aktivitätsart "{$a->modname}" kann ich gerade nicht ändern - bitte der Administration melden. Andere Aktivitätsarten bleiben schreibbar, Lesen und Nachschlagen sind ebenfalls weiterhin möglich.';
$string['driftcheckname'] = 'Kurspilot-Feldkatalog: {$a}';
$string['driftstatusgeprueft'] = 'Geprüft: Feldkatalog manuell für diese Moodle-Hauptversion durchgesehen, keine Abweichung.';
$string['driftstatusautomatischgeprueft'] = 'Automatisch geprüft: Spalten, aufrufbare Quellen und Konstanten stimmen, aber diese Moodle-Hauptversion wurde noch nicht manuell durchgesehen (Wertelisten, Kombinationsregeln, Nebenwirkungen).';
$string['driftstatusbrauchtarbeit'] = 'Braucht Arbeit: der Feldkatalog weicht von dieser Moodle-Instanz ab, die Aktivitätsart ist schreibgesperrt.';

// Spec 0017: clone_activity (#421).
$string['clonenobackupsupport'] = 'Aktivitätsart "{$a->modname}" unterstützt keinen Aktivitäts-Export (kein FEATURE_BACKUP_MOODLE2) und kann deshalb nicht geklont werden.';
$string['clonefailed'] = 'Das Klonen der Aktivität ist fehlgeschlagen - Moodle hat nach Backup/Restore keine neue Aktivität gemeldet. Es bleibt nichts Nutzbares zurück.';

// Administration: WebDAV-Statusprüfungen, Spalte Ablageort, Einstellungsblock (#499, Spec #486 §12).
$string['webdavcheckactionlink'] = 'Jetzt öffnen';
$string['webdavcheck1name'] = 'Kurspilot: WebDAV-Repository aktiv';
$string['webdavcheck1ok'] = 'Der Repository-Typ „WebDAV" ist aktiv.';
$string['webdavcheck1infooptional'] = 'Der Repository-Typ „WebDAV" ist nicht aktiv. Das ist unbedenklich, solange niemand einen externen Ablageort gewählt hat.';
$string['webdavcheck1warning'] = 'Der Repository-Typ „WebDAV" ist nicht aktiv, aber {$a} Person(en) zeigen mit ihrem Kontextpointer bereits auf einen externen Ort. Diese Orte sind gerade unerreichbar.';
$string['webdavcheck2name'] = 'Kurspilot: Nutzerinstanzen erlaubt';
$string['webdavcheck2na'] = 'Nicht anwendbar, solange der Repository-Typ „WebDAV" nicht aktiv ist.';
$string['webdavcheck2ok'] = 'Nutzerinstanzen des Repository-Typs „WebDAV" sind erlaubt.';
$string['webdavcheck2warning'] = 'Nutzerinstanzen des Repository-Typs „WebDAV" sind nicht erlaubt — Lehrkräfte können keine eigene Verbindung anlegen. Empfehlung beim Anlegen: ein App-Passwort statt des eigentlichen Kontopassworts.';
$string['webdavcheck3name'] = 'Kurspilot: WebDAV-Recht im Nutzerkontext';
$string['webdavcheck3na'] = 'Nicht anwendbar: entweder ist der Repository-Typ „WebDAV" nicht aktiv, oder niemand hat gerade eine aktive Kurspilot-Verbindung.';
$string['webdavcheck3ok'] = 'Alle Personen mit aktiver Kurspilot-Verbindung haben das Recht „repository/webdav:view" im eigenen Nutzerkontext.';
$string['webdavcheck3warning'] = '{$a->missing} von {$a->total} verbundenen Lehrkräften fehlt das Recht „repository/webdav:view" im eigenen Nutzerkontext.';
$string['webdavcheck4name'] = 'Kurspilot: Zugelassene Speicher für personenbezogene Daten';
$string['webdavcheck4info'] = 'Keine externen Speicher zugelassen — als personenbezogen markierte Dateien bleiben in Moodle.';
$string['webdavcheck4ok'] = 'Zugelassene externe Speicher sind konfiguriert.';

$string['connectionablageort'] = 'Ablageort';
$string['ablageorttargetkontextbereich'] = 'Kontextbereich';
$string['ablageorttargetmaterialbestand'] = 'Materialbestand';
$string['ablageortoffen'] = 'offen';
$string['ablageortmoodle'] = 'in Moodle';
$string['ablageortextern'] = 'extern: {$a}';
$string['ablageortdefektungueltig'] = 'Pointer ungültig';
$string['ablageortdefektinstanzfehlt'] = 'Instanz fehlt';
$string['ablageortdefektfremdeinstanz'] = 'gehört jemand anderem';
$string['ablageortdefekthttp'] = 'http';
$string['ablageortmarkernichtzugelassen'] = 'nicht zugelassener Speicher';
$string['ablageortmarkerausstand'] = 'offener Ausstand';
$string['ablageortmarkeraltbestand'] = 'offener Altbestand';
$string['ablageortmarkerdefekt'] = 'defekter Pointer ({$a})';

$string['settingwebdavheading'] = 'Externer Ablageort (WebDAV)';
$string['settingwebdavheading_desc'] = 'Was die Schule über den externen Ablageort wissen sollte: Namen und Bilder aus dem Materialbestand können an die KI gehen. Eine WebDAV-Nutzerinstanz speichert das Passwort im Klartext — ein separates App-Passwort statt des eigentlichen Kontopassworts wird empfohlen. Core-Lücke: der Repository-Provider für Auskunft/Löschung sucht über „userid", Nutzerinstanzen tragen aber „userid = 0" und werden dadurch nicht gefunden. Für externe Kontextdateien gilt eine Schreibsperre, aber keine Lesesperre. Der aktuelle Stand der vier zugehörigen Statusprüfungen steht im <a href="{$a}">Systemstatus</a>.';
