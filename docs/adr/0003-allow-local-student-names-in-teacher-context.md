# Allow Local Student Names in Teacher Context Files

Lerngruppenprofile may contain real student names when they stay on the responsible teacher's local machine and are used for concrete lesson planning. These profiles are not part of the Git repository and live in the configured, ignored Kurspilot work area managed by each teacher. The work area itself is the write boundary; it starts directly with school-year folders and no longer contains a `local-context/` intermediate directory.

The project will not treat anonymization as the default for local working data, because teachers plan for real students and need practical, recognizable context. When files are deliberately shared beyond that local working context, personal information belongs in explicitly marked sidecars and must be handled under the sharing rules in specification 0010.

Fortgeschrieben durch [ADR 0011](0011-personenbezogene-kontextdaten-im-servermodell.md): Im Servermodell (`local_kurspilot`) liegen die Kontextdateien im privaten Moodle-Dateibereich, die tragende Begruendung "bleibt lokal beim Verantwortlichen" faellt damit weg. Die Entscheidung selbst bleibt gueltig; Verantwortlichkeit, Abschaltbarkeit und Informationspflicht regelt ADR 0011.
