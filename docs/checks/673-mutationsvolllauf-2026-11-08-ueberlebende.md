# Überlebende Mutanten je Datei (#673, 2026-11-08)

Quelle und Methode: siehe [Hauptbericht](673-mutationsvolllauf-2026-11-08.md). Gesamt 898 Überlebende, davon 874 mit begründetem exaktem Ignore in `scripts/gate/mutation-ignore.json` (gelistet als „ignoriert“), 24 offen. Offene Überlebende sind eine Arbeitsliste, keine Gate-Verletzung.

| Abschnitt | Dateien | Mutanten | getötet | überlebt (ignoriert) | ungedeckt | Fehler | MSI % | Sekunden |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| classes-01 | 10 | 874 | 761 | 55 (39) | 33 | 25 | 87.07 | 11525 |
| classes-02 | 10 | 854 | 826 | 26 (26) | 2 | 0 | 96.72 | 2622 |
| classes-03 | 10 | 1081 | 981 | 99 (99) | 1 | 0 | 90.75 | 2740 |
| classes-04 | 10 | 425 | 403 | 21 (21) | 1 | 0 | 94.82 | 1109 |
| classes-05 | 10 | 712 | 620 | 89 (89) | 3 | 0 | 87.08 | 10560 |
| classes-06 | 8 | 506 | 467 | 33 (27) | 4 | 2 | 92.29 | 3037 |
| classes-activity_files | 1 | 9 | 0 | 0 (0) | 9 | 0 | 0 | 2 |
| classes-admin | 5 | 156 | 153 | 3 (3) | 0 | 0 | 98.08 | 263 |
| classes-catalog-01 | 10 | 1662 | 1565 | 97 (97) | 0 | 0 | 94.16 | 3699 |
| classes-catalog-02 | 10 | 1381 | 1301 | 80 (80) | 0 | 0 | 94.21 | 4206 |
| classes-catalog-03 | 4 | 592 | 573 | 19 (19) | 0 | 0 | 96.79 | 2200 |
| classes-check | 5 | 26 | 19 | 7 (7) | 0 | 0 | 73.08 | 43 |
| classes-event | 4 | 12 | 10 | 2 (2) | 0 | 0 | 83.33 | 45 |
| classes-external-01 | 10 | 608 | 570 | 37 (37) | 1 | 0 | 93.75 | 2263 |
| classes-external-02 | 10 | 411 | 383 | 27 (27) | 1 | 0 | 93.19 | 1081 |
| classes-external-03 | 10 | 492 | 424 | 59 (59) | 9 | 0 | 86.18 | 1989 |
| classes-external-04 | 10 | 227 | 207 | 20 (20) | 0 | 0 | 91.19 | 614 |
| classes-external-05 | 10 | 831 | 759 | 62 (62) | 10 | 0 | 91.34 | 3177 |
| classes-external-06 | 6 | 322 | 294 | 27 (27) | 0 | 1 | 91.3 | 1730 |
| classes-history | 6 | 506 | 428 | 74 (72) | 2 | 2 | 84.58 | 3287 |
| classes-output | 10 | 466 | 444 | 22 (22) | 0 | 0 | 95.28 | 872 |
| classes-privacy | 1 | 209 | 202 | 7 (7) | 0 | 0 | 96.65 | 414 |
| classes-quiz | 1 | 115 | 106 | 9 (9) | 0 | 0 | 92.17 | 862 |
| classes-task | 2 | 2 | 2 | 0 (0) | 0 | 0 | 100 | 5 |
| classes-webdav | 9 | 377 | 354 | 23 (23) | 0 | 0 | 93.9 | 707 |
| db | 2 | 0 | 0 | 0 (0) | 0 | 0 | null | 2 |
| root | 1 | 135 | 135 | 0 (0) | 0 | 0 | 100 | 215 |
| **gesamt** | 185 | 12991 | 11987 | 898 (874) | 76 | 30 | 92.27 | 59270 |

## Plugin/src/local_coursepilot/classes/access_log.php (1 überlebt, 0 offen)

- 60 CastInt (ignoriert): `return (int) $level; => return $level;`

## Plugin/src/local_coursepilot/classes/activity_backup.php (11 überlebt, 0 offen)

- 59 UnwrapFinally (ignoriert): `try { $xml = @file_get_contents(self::backup_path($backupid) . "/activities/{$cm->modname}_{$cm->id}/{$cm->modname}.xml"); } finally { self::discard_tempdir($backupid); } => $xml = @file_get_contents(`
- 85 CastInt (ignoriert): `ackup::MODE_IMPORT, (int) $USER->id); => ackup::MODE_IMPORT, $USER->id);`
- 282 CastInt (ignoriert): `ackup::MODE_IMPORT, (int) $USER->id, \backup:: => ackup::MODE_IMPORT, $USER->id, \backup::`
- 288 LogicalNot (ignoriert): `_array($results) && !empty($results['erro => _array($results) && empty($results['erro`
- 288 LogicalAnd (ignoriert): `(is_array($results) && !empty($results['er => (is_array($results) || !empty($results['er`
- 288 LogicalAndAllSubExprNegation (ignoriert): `if (is_array($results) && !empty($results['erro => if (!is_array($results) && empty($results['erro`
- 288 LogicalAndNegation (ignoriert): `if (is_array($results) && !empty($results['errors'])) { => if (!(is_array($results) && !empty($results['errors']))) {`
- 288 LogicalAndSingleSubExprNegation (ignoriert): `if (is_array($results) & => if (!is_array($results) &`
- 297 CastInt (ignoriert): `return (int) $task->get_moduleid( => return $task->get_moduleid(`
- 306 CastInt (ignoriert): `$owned[(int) $task->get_moduleid( => $owned[$task->get_moduleid(`
- 306 CastInt (ignoriert): `->get_moduleid()] = (int) $task->get_activityi => ->get_moduleid()] = $task->get_activityi`

## Plugin/src/local_coursepilot/classes/activity_file_trash.php (6 überlebt, 0 offen)

- 68 ArrayItemRemoval (ignoriert): `from_storedfile(['contextid' => $file->get_contextid(), 'component' => self::CO => from_storedfile(['component' => self::CO`
- 69 ArrayItem (ignoriert): `edfile(['contextid' => $file->get_context => edfile(['contextid' > $file->get_context`
- 74 ArrayItem (ignoriert): `ilepath, 'filename' => $file->get_filenam => ilepath, 'filename' > $file->get_filenam`
- 97 DecrementInteger (ignoriert): `created DESC', '*', 0, 1); => created DESC', '*', -1, 1);`
- 98 DecrementInteger (ignoriert): `ated DESC', '*', 0, 1); => ated DESC', '*', 0, 0);`
- 98 IncrementInteger (ignoriert): `ated DESC', '*', 0, 1); => ated DESC', '*', 0, 2);`

## Plugin/src/local_coursepilot/classes/admin/connection_storage_location.php (1 überlebt, 0 offen)

- 68 CastString (ignoriert): `data_hosts::allowed((string) $state['host']); => data_hosts::allowed($state['host']);`

## Plugin/src/local_coursepilot/classes/admin/pointer_scan.php (2 überlebt, 0 offen)

- 83 CastInt (ignoriert): `Class $row): int => (int) $row->id, $rows)); => Class $row): int => $row->id, $rows));`
- 208 CastString (ignoriert): `$host = (string) ($location->fingerprint['server'] ?? ''); => $host = $location->fingerprint['server'] ?? '';`

## Plugin/src/local_coursepilot/classes/catalog/assign.php (49 überlebt, 0 offen)

- 499 Concat (ignoriert): `' => $instanceid], 'id, name, intro, allowsubmissionsfromdate, duedate, cutoffdate, gradingduedate, completionsubmit, grade, ' . 'submissiondrafts, requiresubmissionstatement, maxattempts, attemptreop`
- 499 Concat (ignoriert): `submit, grade, ' . 'submissiondrafts, requiresubmissionstatement, maxattempts, attemptreopenmethod, teamsubmission, ' . 'requireallteammemberssubmit, teamsubmissiongroupingid, sendnotifications, sendl`
- 512 ArrayItemRemoval (ignoriert): `grade_items', ['itemtype' => 'mod', 'itemmodule' => 'assign', => grade_items', ['itemmodule' => 'assign',`
- 516 CastString (ignoriert): `$details['name'] = (string) $assign->name; => $details['name'] = $assign->name;`
- 517 CastString (ignoriert): `tate::content_field((string) $assign->intro, $ful => tate::content_field($assign->intro, $ful`
- 541 CastInt (ignoriert): `uedate' => (string) (int) $assign->duedate, 'a => uedate' => (string) $assign->duedate, 'a`
- 541 CastString (ignoriert): `erge(['duedate' => (string) (int) $assign->duedat => erge(['duedate' => (int) $assign->duedat`
- 542 CastInt (ignoriert): `omdate' => (string) (int) $assign->allowsubmis => omdate' => (string) $assign->allowsubmis`
- 542 CastString (ignoriert): `ssionsfromdate' => (string) (int) $assign->allows => ssionsfromdate' => (int) $assign->allows`
- 543 CastInt (ignoriert): `ffdate' => (string) (int) $assign->cutoffdate, => ffdate' => (string) $assign->cutoffdate,`
- 543 CastString (ignoriert): `e, 'cutoffdate' => (string) (int) $assign->cutoff => e, 'cutoffdate' => (int) $assign->cutoff`
- 544 CastInt (ignoriert): `uedate' => (string) (int) $assign->gradingdued => uedate' => (string) $assign->gradingdued`
- 544 CastString (ignoriert): `gradingduedate' => (string) (int) $assign->gradin => gradingduedate' => (int) $assign->gradin`
- 545 CastInt (ignoriert): `submit' => (string) (int) $assign->completions => submit' => (string) $assign->completions`
- 545 CastString (ignoriert): `mpletionsubmit' => (string) (int) $assign->comple => mpletionsubmit' => (int) $assign->comple`
- 546 CastInt (ignoriert): `'grade' => (string) (int) $assign->grade, 'gra => 'grade' => (string) $assign->grade, 'gra`
- 546 CastString (ignoriert): `submit, 'grade' => (string) (int) $assign->grade, => submit, 'grade' => (int) $assign->grade,`
- 547 CastString (ignoriert): `de, 'gradepass' => (string) (float) ($gradeitem-> => de, 'gradepass' => (float) ($gradeitem->`
- 548 CastInt (ignoriert): `drafts' => (string) (int) $assign->submissiond => drafts' => (string) $assign->submissiond`
- 548 CastString (ignoriert): `bmissiondrafts' => (string) (int) $assign->submis => bmissiondrafts' => (int) $assign->submis`
- 549 CastInt (ignoriert): `tempts' => (string) (int) $assign->maxattempts => tempts' => (string) $assign->maxattempts`
- 549 CastString (ignoriert): `, 'maxattempts' => (string) (int) $assign->maxatt => , 'maxattempts' => (int) $assign->maxatt`
- 550 CastString (ignoriert): `mptreopenmethod' => (string) $assign->attemptreop => mptreopenmethod' => $assign->attemptreop`
- 551 CastInt (ignoriert): `tement' => (string) (int) $assign->requiresubm => tement' => (string) $assign->requiresubm`
- 551 CastString (ignoriert): `ssionstatement' => (string) (int) $assign->requir => ssionstatement' => (int) $assign->requir`
- 552 CastInt (ignoriert): `ission' => (string) (int) $assign->teamsubmiss => ission' => (string) $assign->teamsubmiss`
- 552 CastString (ignoriert): `teamsubmission' => (string) (int) $assign->teamsu => teamsubmission' => (int) $assign->teamsu`
- 553 CastInt (ignoriert): `submit' => (string) (int) $assign->requireallt => submit' => (string) $assign->requireallt`
- 553 CastString (ignoriert): `mmemberssubmit' => (string) (int) $assign->requir => mmemberssubmit' => (int) $assign->requir`
- 554 CastInt (ignoriert): `pingid' => (string) (int) $assign->teamsubmiss => pingid' => (string) $assign->teamsubmiss`
- 554 CastString (ignoriert): `siongroupingid' => (string) (int) $assign->teamsu => siongroupingid' => (int) $assign->teamsu`
- 555 CastInt (ignoriert): `ations' => (string) (int) $assign->sendnotific => ations' => (string) $assign->sendnotific`
- 555 CastString (ignoriert): `dnotifications' => (string) (int) $assign->sendno => dnotifications' => (int) $assign->sendno`
- 556 CastInt (ignoriert): `ations' => (string) (int) $assign->sendlatenot => ations' => (string) $assign->sendlatenot`
- 556 CastString (ignoriert): `enotifications' => (string) (int) $assign->sendla => enotifications' => (int) $assign->sendla`
- 557 CastInt (ignoriert): `ations' => (string) (int) $assign->sendstudent => ations' => (string) $assign->sendstudent`
- 557 CastString (ignoriert): `tnotifications' => (string) (int) $assign->sendst => tnotifications' => (int) $assign->sendst`
- 558 CastInt (ignoriert): `arking' => (string) (int) $assign->blindmarkin => arking' => (string) $assign->blindmarkin`
- 558 CastString (ignoriert): `'blindmarking' => (string) (int) $assign->blindm => 'blindmarking' => (int) $assign->blindm`
- 559 CastInt (ignoriert): `rkflow' => (string) (int) $assign->markingwork => rkflow' => (string) $assign->markingwork`
- 559 CastString (ignoriert): `arkingworkflow' => (string) (int) $assign->markin => arkingworkflow' => (int) $assign->markin`
- 560 CastInt (ignoriert): `cation' => (string) (int) $assign->markingallo => cation' => (string) $assign->markingallo`
- 560 CastString (ignoriert): `kingallocation' => (string) (int) $assign->markin => kingallocation' => (int) $assign->markin`
- 561 CastInt (ignoriert): `decat' => (string) (int) ($gradeitem->category => decat' => (string) ($gradeitem->category`
- 561 CastString (ignoriert): `ion, 'gradecat' => (string) (int) ($gradeitem->ca => ion, 'gradecat' => (int) ($gradeitem->ca`
- 562 CastString (ignoriert): `'gradingmethod' => (string) ($gradingmethod ?: 'none'), 'additionalfiles' => 'gradingmethod' => $gradingmethod ?: 'none', 'additionalfiles'`
- 861 CastInt (ignoriert): `if ((int) $CFG->branch >= 502) => if ($CFG->branch >= 502)`
- 864 CastInt (ignoriert): `if ((int) $CFG->branch >= 503) => if ($CFG->branch >= 503)`
- 1029 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`

## Plugin/src/local_coursepilot/classes/catalog/catalog_fields.php (1 überlebt, 0 offen)

- 39 UnwrapArrayUnique (ignoriert): `$blocklist = array_unique(array_merge(shared_block::blocked_fields(), $catalogclass::blocklist())); => $blocklist = array_merge(shared_block::blocked_fields(), $catalogclass::blocklist());`

## Plugin/src/local_coursepilot/classes/catalog/choice.php (2 überlebt, 0 offen)

- 441 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 441 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/completion_settings.php (32 überlebt, 0 offen)

- 114 UnwrapArrayIntersectKey (ignoriert): `y_keys(array_filter(array_intersect_key(self::FIELDS, self::allowed_fields($modname)), static fn(array $s => y_keys(array_filter(self::FIELDS, static fn(array $s`
- 137 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 146 CastString (ignoriert): `$modname = (string) $cm->modname; => $modname = $cm->modname;`
- 155 CastInt (ignoriert): `course = get_course((int) $cm->course); => course = get_course($cm->course);`
- 171 CastInt (ignoriert): `return ['cmid' => (int) $cmid, 'modname' => => return ['cmid' => $cmid, 'modname' =>`
- 172 CastString (ignoriert): `$cmid, 'modname' => (string) $cm->modname, 'messa => $cmid, 'modname' => $cm->modname, 'messa`
- 180 CastInt (ignoriert): `ocks($catalogclass, (int) $cm->instance, $befo => ocks($catalogclass, $cm->instance, $befo`
- 197 CastInt (ignoriert): `return ['cmid' => (int) $cmid, 'modname' => => return ['cmid' => $cmid, 'modname' =>`
- 198 CastString (ignoriert): `$cmid, 'modname' => (string) $cm->modname, 'messa => $cmid, 'modname' => $cm->modname, 'messa`
- 225 DecrementInteger (ignoriert): `$before[$field] ?? 0); => $before[$field] ?? -1);`
- 225 IncrementInteger (ignoriert): `$before[$field] ?? 0); => $before[$field] ?? 1);`
- 225 CastInt (ignoriert): `ing $field): int => (int) ($patch[$field] ?? $before[$field] ?? 0); => ing $field): int => $patch[$field] ?? $before[$field] ?? 0;`
- 241 UnwrapArrayValues (ignoriert): `r has graded it.'], array_values($fields)); => r has graded it.'], $fields);`
- 257 CastInt (ignoriert): `$affected = (int) $DB->count_records(' => $affected = $DB->count_records('`
- 319 DecrementInteger (ignoriert): `ionminattempts'] ?? 0; => ionminattempts'] ?? -1;`
- 319 IncrementInteger (ignoriert): `ionminattempts'] ?? 0; => ionminattempts'] ?? 1;`
- 320 DecrementInteger (ignoriert): `fore['attempts'] ?? 0); => fore['attempts'] ?? -1);`
- 320 IncrementInteger (ignoriert): `fore['attempts'] ?? 0); => fore['attempts'] ?? 1);`
- 320 CastInt (ignoriert): `$limit = (int) ($before['attempts'] ?? 0); => $limit = $before['attempts'] ?? 0;`
- 378 DecrementInteger (ignoriert): `($before[$field] ?? 0) !== (int) $patch[$ => ($before[$field] ?? -1) !== (int) $patch[$`
- 378 IncrementInteger (ignoriert): `($before[$field] ?? 0) !== (int) $patch[$ => ($before[$field] ?? 1) !== (int) $patch[$`
- 378 CastInt (ignoriert): `e[$field] ?? 0) !== (int) $patch[$field]) { => e[$field] ?? 0) !== $patch[$field]) {`
- 411 CastInt (ignoriert): `s($field, $patch) ? (int) $patch[$field] : (in => s($field, $patch) ? $patch[$field] : (in`
- 411 DecrementInteger (ignoriert): `($before[$field] ?? 0); => ($before[$field] ?? -1);`
- 411 IncrementInteger (ignoriert): `($before[$field] ?? 0); => ($before[$field] ?? 1);`
- 411 CastInt (ignoriert): `t) $patch[$field] : (int) ($before[$field] ?? 0); => t) $patch[$field] : $before[$field] ?? 0;`
- 417 Ternary (ignoriert): `pletionusegrade'] ? 0 : null; => pletionusegrade'] ? null : 0;`
- 420 GreaterThan (ignoriert): `etionminattempts'] > 0; => etionminattempts'] >= 0;`
- 424 IncrementInteger (ignoriert): `ompletionunlocked = 1; => ompletionunlocked = 2;`
- 439 UnwrapArrayUnique (ignoriert): `foreach (array_unique($fields) as $fieldname) { => foreach ($fields as $fieldname) {`
- 445 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`
- 446 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`

## Plugin/src/local_coursepilot/classes/catalog/drift_check.php (5 überlebt, 0 offen)

- 171 UnwrapArrayValues (ignoriert): `$known = array_values(array_unique($known)); => $known = array_unique($known);`
- 178 UnwrapArrayValues (ignoriert): `$added = array_values(array_diff($realcolumns, $known)); => $added = array_diff($realcolumns, $known);`
- 179 UnwrapArrayValues (ignoriert): `$removed = array_values(array_diff($known, $realcolumns)); => $removed = array_diff($known, $realcolumns);`
- 227 UnwrapArrayMerge (ignoriert): `ants = array_unique(array_merge(shared_block::checked_constants(), $constants)); => ants = array_unique($constants);`
- 257 ArrayItemRemoval (ignoriert): `ditor_content'] ?? [[]])), $options['read_ => ditor_content'] ?? [])), $options['read_`

## Plugin/src/local_coursepilot/classes/catalog/folder.php (2 überlebt, 0 offen)

- 272 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 272 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/forum.php (2 überlebt, 0 offen)

- 330 CastInt (ignoriert): `if ((int) $CFG->branch >= 502) => if ($CFG->branch >= 502)`
- 491 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`

## Plugin/src/local_coursepilot/classes/catalog/label.php (4 überlebt, 0 offen)

- 88 CastString (ignoriert): `$details['name'] = (string) $label->name; => $details['name'] = $label->name;`
- 89 CastString (ignoriert): `tate::content_field((string) $label->intro, $full => tate::content_field($label->intro, $full`
- 193 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 193 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/learner_locks.php (4 überlebt, 0 offen)

- 100 CastFloat (ignoriert): `_numeric($value) && (float) $value > (float) $co => _numeric($value) && $value > (float) $co`
- 100 CastFloat (ignoriert): `&& (float) $value > (float) $condition['value']; => && (float) $value > $condition['value'];`
- 102 CastFloat (ignoriert): `_numeric($value) && (float) $value != 0; => _numeric($value) && $value != 0;`
- 234 UnwrapArrayValues (ignoriert): `$open = array_values(array_filter($found, static fn(array $lock): bool => !in_array($lock['id'], $confirmed, true))); => $open = array_filter($found, static fn(array $lock): bool => !in_array($lock['i`

## Plugin/src/local_coursepilot/classes/catalog/module_settings.php (9 überlebt, 0 offen)

- 66 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 67 CastInt (ignoriert): `course = get_course((int) $cm->course); => course = get_course($cm->course);`
- 76 ArrayItemRemoval (ignoriert): `tings($cmid), $cm, [$fieldname => $draftitemid]); => tings($cmid), $cm, []);`
- 108 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 111 CastString (ignoriert): `$modname = (string) $cm->modname; => $modname = $cm->modname;`
- 128 CastInt (ignoriert): `ss, $cm, get_course((int) $cm->course), $patch => ss, $cm, get_course($cm->course), $patch`
- 139 CastInt (ignoriert): `return ['cmid' => (int) $cmid, 'modname' => => return ['cmid' => $cmid, 'modname' =>`
- 280 CastObject (ignoriert): `local_coursepilot', (object) ['field' => $change[ => local_coursepilot', ['field' => $change[`
- 306 CastObject (ignoriert): `local_coursepilot', (object) ['field' => $fieldna => local_coursepilot', ['field' => $fieldna`

## Plugin/src/local_coursepilot/classes/catalog/module_state.php (11 überlebt, 0 offen)

- 44 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 50 CastString (ignoriert): `ass = registry::for((string) $cm->modname); => ass = registry::for($cm->modname);`
- 54 CastString (ignoriert): `m->id, 'modname' => (string) $cm->modname, 'setti => m->id, 'modname' => $cm->modname, 'setti`
- 82 CastString (ignoriert): `le, 'modulename' => (string) $cm->modname, 'insta => le, 'modulename' => $cm->modname, 'insta`
- 89 CastInt (ignoriert): `::derive_visibility((int) $cm->visible, (int) => ::derive_visibility($cm->visible, (int)`
- 90 CastInt (ignoriert): `(int) $cm->visible, (int) $cm->visibleoncourse => (int) $cm->visible, $cm->visibleoncourse`
- 93 CastString (ignoriert): `y_privacy::sanitize((string) ($cm->availability ?? '')); => y_privacy::sanitize($cm->availability ?? '');`
- 95 CastString (ignoriert): `ass = registry::for((string) $cm->modname); => ass = registry::for($cm->modname);`
- 97 CastInt (ignoriert): `oups($catalogclass, (int) $cm->instance)); => oups($catalogclass, $cm->instance));`
- 100 CastInt (ignoriert): `uiz::grade_settings((int) $cm->instance)); => uiz::grade_settings($cm->instance));`
- 122 CastString (ignoriert): `'name'] = $record ? (string) $record->name : ''; => 'name'] = $record ? $record->name : '';`

## Plugin/src/local_coursepilot/classes/catalog/page.php (3 überlebt, 0 offen)

- 141 CastString (ignoriert): `$details['name'] = (string) $page->name; => $details['name'] = $page->name;`
- 311 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 311 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/pseudofield_carry_forward.php (11 überlebt, 0 offen)

- 79 Continue_ (ignoriert): `continue; => break;`
- 82 Continue_ (ignoriert): `continue; => break;`
- 87 Continue_ (ignoriert): `continue; => break;`
- 91 Continue_ (ignoriert): `continue; => break;`
- 245 Continue_ (ignoriert): `continue; => break;`
- 252 Concat (ignoriert): `tance($cm->id)->id, 'mod_' . $modname, $content, 0, [], ( => tance($cm->id)->id, $modname . 'mod_', $content, 0, [], (`
- 252 ConcatOperandRemoval (ignoriert): `tance($cm->id)->id, 'mod_' . $modname, $content, => tance($cm->id)->id, $modname, $content,`
- 252 ConcatOperandRemoval (ignoriert): `$cm->id)->id, 'mod_' . $modname, $content, 0, [], ( => $cm->id)->id, 'mod_', $content, 0, [], (`
- 254 DecrementInteger (ignoriert): `$modname, $content, 0, [], (string) ($pat => $modname, $content, -1, [], (string) ($pat`
- 254 IncrementInteger (ignoriert): `$modname, $content, 0, [], (string) ($pat => $modname, $content, 1, [], (string) ($pat`
- 256 CastString (ignoriert): `e, $content, 0, [], (string) ($patch[$content] ?? $before[$content] ?? '')); => e, $content, 0, [], $patch[$content] ?? $before[$content] ?? '');`

## Plugin/src/local_coursepilot/classes/catalog/quiz.php (21 überlebt, 0 offen)

- 418 CastInt (ignoriert): `if ((int) $CFG->branch >= 503) => if ($CFG->branch >= 503)`
- 455 CastInt (ignoriert): `elf::grade_settings((int) $quiz->id); => elf::grade_settings($quiz->id);`
- 456 CastString (ignoriert): `$details['name'] = (string) $quiz->name; => $details['name'] = $quiz->name;`
- 457 CastString (ignoriert): `tate::content_field((string) $quiz->intro, $fullc => tate::content_field($quiz->intro, $fullc`
- 459 CastString (ignoriert): `ferredbehaviour' => (string) $quiz->preferredbeha => ferredbehaviour' => $quiz->preferredbeha`
- 459 CastInt (ignoriert): `tempts' => (string) (int) $quiz->attempts, 'gr => tempts' => (string) $quiz->attempts, 'gr`
- 459 CastString (ignoriert): `our, 'attempts' => (string) (int) $quiz->attempts => our, 'attempts' => (int) $quiz->attempts`
- 460 CastInt (ignoriert): `method' => (string) (int) $quiz->grademethod, => method' => (string) $quiz->grademethod,`
- 460 CastString (ignoriert): `, 'grademethod' => (string) (int) $quiz->grademet => , 'grademethod' => (int) $quiz->grademet`
- 460 CastInt (ignoriert): `elimit' => (string) (int) $quiz->timelimit, 'g => elimit' => (string) $quiz->timelimit, 'g`
- 460 CastString (ignoriert): `od, 'timelimit' => (string) (int) $quiz->timelimi => od, 'timelimit' => (int) $quiz->timelimi`
- 461 CastString (ignoriert): `elimit, 'grade' => (string) (float) $quiz->grade, => elimit, 'grade' => (float) $quiz->grade,`
- 461 CastString (ignoriert): `ade, 'gradepass' => (string) $gradesettings['grad => ade, 'gradepass' => $gradesettings['grad`
- 462 CastString (ignoriert): `ss'], 'grademax' => (string) $gradesettings['grad => ss'], 'grademax' => $gradesettings['grad`
- 464 CastInt (ignoriert): `= self::quiz_slots((int) $quiz->id); => = self::quiz_slots($quiz->id);`
- 524 CastInt (ignoriert): `elf::grade_settings((int) $instance->id), ['qu => elf::grade_settings($instance->id), ['qu`
- 526 CastString (ignoriert): `['quizpassword' => (string) $instance->password, => ['quizpassword' => $instance->password,`
- 534 CastInt (ignoriert): `idge::read_feedback((int) $instance->id)); => idge::read_feedback($instance->id));`
- 546 ArrayItemRemoval (ignoriert): `grade_items', ['itemtype' => 'mod', 'itemmodule' => 'quiz', ' => grade_items', ['itemmodule' => 'quiz', '`
- 564 CastInt (ignoriert): `if ((int) $CFG->branch >= 503) => if ($CFG->branch >= 503)`
- 855 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`

## Plugin/src/local_coursepilot/classes/catalog/quiz_write.php (14 überlebt, 0 offen)

- 70 CastInt (ignoriert): `oduleinfo->module = (int) $module->id; => oduleinfo->module = $module->id;`
- 91 CastInt (ignoriert): `$cmid = (int) $created->coursemodu => $cmid = $created->coursemodu`
- 135 CastFloat (ignoriert): `ade >= 0 ? $grade : (float) $quiz->grade; => ade >= 0 ? $grade : $quiz->grade;`
- 138 CastFloat (ignoriert): `= 0 && abs($grade - (float) $quiz->grade) > 1.0E => = 0 && abs($grade - $quiz->grade) > 1.0E`
- 143 CastInt (ignoriert): `:apply_grade_change((int) $quiz->id, $grade); => :apply_grade_change($quiz->id, $grade);`
- 146 CastInt (ignoriert): `course = get_course((int) $cm->course); => course = get_course($cm->course);`
- 203 CastString (ignoriert): `nfo->quizpassword = (string) $quiz->password; => nfo->quizpassword = $quiz->password;`
- 206 CastInt (ignoriert): `edback($moduleinfo, (int) $quiz->id, $feedback => edback($moduleinfo, $quiz->id, $feedback`
- 211 MethodCallRemoval (ignoriert): `// Get_moduleinfo_data() returns gradepass in display format // ("0,00"). Writing it back unchecked fails at the database after the // change has already persisted. pseudofield_carry_forward::unformat`
- 279 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`
- 330 UnwrapArrayUnique (ignoriert): `foreach (array_unique($fieldnames) as $fieldname) { => foreach ($fieldnames as $fieldname) {`
- 367 CastInt (ignoriert): `eopen', $merged) && (int) $after['timeopen'] > => eopen', $merged) && $after['timeopen'] >`
- 368 CastInt (ignoriert): `close', $merged) && (int) $after['timeclose'] => close', $merged) && $after['timeclose']`
- 393 CastObject (ignoriert): `local_coursepilot', (object) ['field' => $change[ => local_coursepilot', ['field' => $change[`

## Plugin/src/local_coursepilot/classes/catalog/quiz_write_bridge.php (7 überlebt, 0 offen)

- 75 IncrementInteger (ignoriert): `review' . $type} ?? 0); => review' . $type} ?? 1);`
- 75 CastInt (ignoriert): `$mask = (int) ($quiz->{'review' . $type} ?? 0); => $mask = $quiz->{'review' . $type} ?? 0;`
- 97 CastString (ignoriert): `$texts[] = (string) $record->feedbacktex => $texts[] = $record->feedbacktex`
- 133 CastFloat (ignoriert): `$configured = (float) get_config('quiz', ' => $configured = get_config('quiz', '`
- 215 CastInt (ignoriert): `$min = (int) get_config('quiz', ' => $min = get_config('quiz', '`
- 300 UnwrapSubstr (ignoriert): `return (float) trim(substr($value, 0, -1)) * $grade / 100.0; => return (float) trim($value) * $grade / 100.0;`
- 300 UnwrapTrim (ignoriert): `return (float) trim(substr($value, 0, -1)) * $grade / 100.0; => return (float) substr($value, 0, -1) * $grade / 100.0;`

## Plugin/src/local_coursepilot/classes/catalog/resource.php (2 überlebt, 0 offen)

- 324 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 324 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/url.php (4 überlebt, 0 offen)

- 131 CastString (ignoriert): `$details['name'] = (string) $url->name; => $details['name'] = $url->name;`
- 133 CastString (ignoriert): `s(['externalurl' => (string) $url->externalurl]); => s(['externalurl' => $url->externalurl]);`
- 297 DecrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = -1): string`
- 297 IncrementInteger (ignoriert): `n(int $instanceid = 0): string => n(int $instanceid = 1): string`

## Plugin/src/local_coursepilot/classes/catalog/write_target.php (13 überlebt, 0 offen)

- 146 CastInt (ignoriert): `name(), 'module' => (int) $module->id, 'sectio => name(), 'module' => $module->id, 'sectio`
- 163 CastInt (ignoriert): `ogclass::modname(), (int) $created->coursemodu => ogclass::modname(), $created->coursemodu`
- 164 CastInt (ignoriert): `return (int) $created->coursemodu => return $created->coursemodu`
- 213 CastInt (ignoriert): `ogclass::modname(), (int) $cm->id); => ogclass::modname(), $cm->id);`
- 230 FalseValue (ignoriert): `'assign', $cmid, 0, false, MUST_EXIST); => 'assign', $cmid, 0, true, MUST_EXIST);`
- 231 ArrayItemRemoval (ignoriert): `$params = ['courseid' => $cm->course, 'itemtype' => 'mod', => $params = ['itemtype' => 'mod',`
- 237 GreaterThan (ignoriert): `if ($count > 1) { => if ($count >= 1) {`
- 245 ArrayItemRemoval (ignoriert): `nt->id, 0, null, ['itemname' => $assignment->name, 'idnumber' => $cm->idn => nt->id, 0, null, ['idnumber' => $cm->idn`
- 246 ArrayItem (ignoriert): `, null, ['itemname' => $assignment->name, => , null, ['itemname' > $assignment->name,`
- 246 ArrayItem (ignoriert): `t->name, 'idnumber' => $cm->idnumber, 'gr => t->name, 'idnumber' > $cm->idnumber, 'gr`
- 299 Continue_ (ignoriert): `continue; => break;`
- 484 FalseValue (ignoriert): `a'], 0, 'filename', false); => a'], 0, 'filename', true);`
- 600 Continue_ (ignoriert): `continue; => break;`

## Plugin/src/local_coursepilot/classes/check/personal_data_hosts_check.php (1 überlebt, 0 offen)

- 59 ArrayItemRemoval (ignoriert): `min/settings.php', ['section' => 'local_coursepilot']), get_string('webd => min/settings.php', []), get_string('webd`

## Plugin/src/local_coursepilot/classes/check/webdav_capability_check.php (4 überlebt, 0 offen)

- 65 CastInt (ignoriert): `etup_steps::catalog((int) $USER->id); => etup_steps::catalog($USER->id);`
- 74 CastInt (ignoriert): `ass $token): int => (int) $token->userid, conn => ass $token): int => $token->userid, conn`
- 87 CastString (ignoriert): `? fullname($user) : (string) $userid; => ? fullname($user) : $userid;`
- 97 CastObject (ignoriert): `local_coursepilot', (object) ['missing' => count( => local_coursepilot', ['missing' => count(`

## Plugin/src/local_coursepilot/classes/check/webdav_repository_check.php (1 überlebt, 0 offen)

- 65 CastInt (ignoriert): `etup_steps::catalog((int) $USER->id)[webdav_se => etup_steps::catalog($USER->id)[webdav_se`

## Plugin/src/local_coursepilot/classes/check/webdav_user_instances_check.php (1 überlebt, 0 offen)

- 61 CastInt (ignoriert): `etup_steps::catalog((int) $USER->id); => etup_steps::catalog($USER->id);`

## Plugin/src/local_coursepilot/classes/client_registry.php (1 überlebt, 0 offen)

- 79 ArrayItemRemoval (ignoriert): `rray($authmethod, ['none', 'client_secret_post'] => rray($authmethod, ['client_secret_post']`

## Plugin/src/local_coursepilot/classes/cm_references.php (5 überlebt, 0 offen)

- 56 CastString (ignoriert): `elf::tree_points_at((string) $other->availability => elf::tree_points_at($other->availability`
- 73 CastString (ignoriert): `elf::tree_points_at((string) $section->availabili => elf::tree_points_at($section->availabili`
- 141 DecrementInteger (ignoriert): `t) ($node['cm'] ?? -1) === 0; => t) ($node['cm'] ?? -2) === 0;`
- 157 DecrementInteger (ignoriert): `t) ($node['cm'] ?? -1) === $cmid) { => t) ($node['cm'] ?? -2) === $cmid) {`
- 157 IncrementInteger (ignoriert): `t) ($node['cm'] ?? -1) === $cmid) { => t) ($node['cm'] ?? -0) === $cmid) {`

## Plugin/src/local_coursepilot/classes/connection.php (31 überlebt, 16 offen)

- 95 CastInt (ignoriert): `if (!$record || (int) $record->used === 1 => if (!$record || $record->used === 1`
- 95 IncrementInteger (ignoriert): `) $record->used === 1 || $record->expires => ) $record->used === 2 || $record->expires`
- 112 CastInt (ignoriert): `ken_pair($clientid, (int) $record->userid); => ken_pair($clientid, $record->userid);`
- 157 CastInt (ignoriert): `lf::lock_connection((int) $record->connectioni => lf::lock_connection($record->connectioni`
- 163 CastInt (ignoriert): `e_locked_connection((int) $record->connectioni => e_locked_connection($record->connectioni`
- 171 CastInt (ignoriert): `ken_pair($clientid, (int) $record->userid, (in => ken_pair($clientid, $record->userid, (in`
- 171 CastInt (ignoriert): `t) $record->userid, (int) $record->connectioni => t) $record->userid, $record->connectioni`
- 193 GreaterThanOrEqualTo: `nt->refreshexpires >= time() ? 'usable' : => nt->refreshexpires > time() ? 'usable' :`
- 205 Concat: `im = hash('sha256', $value . '|' . bin2hex(random_by => im = hash('sha256', '|' . $value . bin2hex(random_by`
- 205 ConcatOperandRemoval: `im = hash('sha256', $value . '|' . bin2hex(random => im = hash('sha256', '|' . bin2hex(random`
- 205 ConcatOperandRemoval: `('sha256', $value . '|' . bin2hex(random_bytes => ('sha256', $value . bin2hex(random_bytes`
- 205 DecrementInteger: `n2hex(random_bytes(16))); => n2hex(random_bytes(15)));`
- 205 IncrementInteger: `n2hex(random_bytes(16))); => n2hex(random_bytes(17)));`
- 205 Concat: `('sha256', $value . '|' . bin2hex(random_bytes(16))); => ('sha256', $value . bin2hex(random_bytes(16)) . '|');`
- 205 ConcatOperandRemoval: `ha256', $value . '|' . bin2hex(random_bytes(16))); => ha256', $value . '|');`
- 241 CastObject: `(self::GRANT_TABLE, (object) ['userid' => $userid => (self::GRANT_TABLE, ['userid' => $userid`
- 241 CastInt: `$connectionid = (int) $DB->insert_record(s => $connectionid = $DB->insert_record(s`
- 243 DecrementInteger: `n2hex(random_bytes(32)), 'timecreated' => => n2hex(random_bytes(31)), 'timecreated' =>`
- 243 ArrayItem: `' => 0, 'statehash' => bin2hex(random_byt => ' => 0, 'statehash' > bin2hex(random_byt`
- 392 CastInt: `if (!$record || (int) $record->revoked === => if (!$record || $record->revoked ===`
- 392 IncrementInteger: `record->revoked === 1 || $record->expires => record->revoked === 2 || $record->expires`
- 448 LogicalAnd (ignoriert): `return $record && !empty($record->connectionid) && self::grant_act => return ($record || !empty($record->connectionid)) && self::grant_act`
- 449 CastInt (ignoriert): `cord->connectionid, (int) $record->userid); => cord->connectionid, $record->userid);`
- 478 DecrementInteger (ignoriert): `$count = 0; => $count = -1;`
- 488 ArrayItemRemoval (ignoriert): `BLE, 'revoked', 1, ['revoked' => 0]); => BLE, 'revoked', 1, []);`
- 520 LogicalOr (ignoriert): `if (!$record || empty($record->conn => if (!$record && empty($record->conn`
- 525 CastInt (ignoriert): `lf::lock_connection((int) $record->connectioni => lf::lock_connection($record->connectioni`
- 529 CastInt (ignoriert): `e_locked_connection((int) $record->connectioni => e_locked_connection($record->connectioni`
- 570 FalseValue (ignoriert): `ame()->get_sql('u', false, '', '', false)->s => ame()->get_sql('u', true, '', '', false)->s`
- 609 DecrementInteger: `n2hex(random_bytes(32)); => n2hex(random_bytes(31));`
- 615 ArrayItemRemoval: `elf::GRANT_TABLE, ['id' => $id, 'statehash' => $marke => elf::GRANT_TABLE, ['statehash' => $marke`

## Plugin/src/local_coursepilot/classes/context_area.php (2 überlebt, 0 offen)

- 333 Throw_ (ignoriert): `throw $e; => $e;`
- 374 MethodCallRemoval (ignoriert): `context_files::guard_existing_locked($existing['content'] ?? null, $path); => `

## Plugin/src/local_coursepilot/classes/context_files.php (3 überlebt, 0 offen)

- 246 UnwrapArrayValues (ignoriert): `$fs->file_exists(...array_values($target))) { => $fs->file_exists(...$target)) {`
- 251 CastInt (ignoriert): `storedfile($target, (int) $record->id); => storedfile($target, $record->id);`
- 254 MethodCallRemoval (ignoriert): `$legacy->close(); => `

## Plugin/src/local_coursepilot/classes/context_pointer.php (4 überlebt, 0 offen)

- 121 FalseValue (ignoriert): `erprint['iserv'] ?? false) === true) { => erprint['iserv'] ?? true) === true) {`
- 122 CastString (ignoriert): `$first = strtok((string) $location->relativep => $first = strtok($location->relativep`
- 210 FalseValue (ignoriert): `erprint['iserv'] ?? false) === true) { => erprint['iserv'] ?? true) === true) {`
- 211 CastString (ignoriert): `$first = strtok((string) $location->relativep => $first = strtok($location->relativep`

## Plugin/src/local_coursepilot/classes/course_module_placement.php (4 überlebt, 0 offen)

- 66 FalseValue (ignoriert): `if ($position === false) { => if ($position === true) {`
- 70 CastInt (ignoriert): `elf::move_to($cmid, (int) $after->section, $su => elf::move_to($cmid, $after->section, $su`
- 70 CastInt (ignoriert): `r === null ? null : (int) $successor); => r === null ? null : $successor);`
- 81 CastInt (ignoriert): `return (int) $DB->get_field('cour => return $DB->get_field('cour`

## Plugin/src/local_coursepilot/classes/dispatcher.php (3 überlebt, 0 offen)

- 394 CastString (ignoriert): `ode: \S+\s*$/', '', (string) ($exception->debuginfo ?? ''))); => ode: \S+\s*$/', '', $exception->debuginfo ?? ''));`
- 415 CastString (ignoriert): `ode: \S+\s*$/', '', (string) ($exception->debuginfo ?? ''))); => ode: \S+\s*$/', '', $exception->debuginfo ?? ''));`
- 506 CastString (ignoriert): `return (string) plugin_meta::current => return plugin_meta::current`

## Plugin/src/local_coursepilot/classes/event/tool_access_failed.php (1 überlebt, 0 offen)

- 77 MethodCallRemoval (ignoriert): `parent::validate_data(); => `

## Plugin/src/local_coursepilot/classes/event/tool_access_succeeded.php (1 überlebt, 0 offen)

- 72 MethodCallRemoval (ignoriert): `parent::validate_data(); => `

## Plugin/src/local_coursepilot/classes/external/add_glossary_entries.php (1 überlebt, 0 offen)

- 96 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`

## Plugin/src/local_coursepilot/classes/external/add_questions_to_quiz.php (13 überlebt, 0 offen)

- 89 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 103 CastInt (ignoriert): `(quiz_has_attempts((int) $quiz->id)) { => (quiz_has_attempts($quiz->id)) {`
- 107 CastInt (ignoriert): `$quiz->cmid = (int) $cm->id; => $quiz->cmid = $cm->id;`
- 112 CastInt (ignoriert): `question', ['id' => (int) $questionid], '*', M => question', ['id' => $questionid], '*', M`
- 119 CastInt (ignoriert): `z_add_quiz_question((int) $questionid, $quiz); => z_add_quiz_question($questionid, $quiz);`
- 122 CastInt (ignoriert): `question_bank_entry((int) $questionid); => question_bank_entry($questionid);`
- 125 CastInt (ignoriert): `= ['questionid' => (int) $questionid, 'questi => = ['questionid' => $questionid, 'questi`
- 127 CastString (ignoriert): `ntry->id, 'name' => (string) $question->name, 'ad => ntry->id, 'name' => $question->name, 'ad`
- 128 CastBool (ignoriert): `n->name, 'added' => (bool) $added]; => n->name, 'added' => $added];`
- 133 CastInt (ignoriert): `::capture_on_update((int) $cm->id, (int) $USER => ::capture_on_update($cm->id, (int) $USER`
- 133 CastInt (ignoriert): `date((int) $cm->id, (int) $USER->id); => date((int) $cm->id, $USER->id);`
- 140 CastInt (ignoriert): `=> self::slot_state((int) $quiz->id)]; => => self::slot_state($quiz->id)];`
- 201 CastString (ignoriert): `>version, 'name' => (string) $row->questionname]; => >version, 'name' => $row->questionname];`

## Plugin/src/local_coursepilot/classes/external/append_context_file.php (1 überlebt, 0 offen)

- 136 CastObject (ignoriert): `local_coursepilot', (object) ['path' => $result[' => local_coursepilot', ['path' => $result['`

## Plugin/src/local_coursepilot/classes/external/clone_activity.php (11 überlebt, 0 offen)

- 104 DecrementInteger (ignoriert): `t $targetcourseid = 0, bool $visible = tr => t $targetcourseid = -1, bool $visible = tr`
- 119 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 143 CastInt (ignoriert): `::capture($newcmid, (int) $USER->id, version_w => ::capture($newcmid, $USER->id, version_w`
- 143 CastInt (ignoriert): `ter::SOURCE_CLONED, (int) $cm->id); => ter::SOURCE_CLONED, $cm->id);`
- 204 CastString (ignoriert): `if ((string) $newcm->availability => if ($newcm->availability`
- 208 CastString (ignoriert): `$tree = json_decode((string) $newcm->availability => $tree = json_decode($newcm->availability`
- 214 CastString (ignoriert): `if ((string) ($sourcecm->availabil => if (($sourcecm->availabil`
- 215 CastString (ignoriert): `coded = json_decode((string) $sourcecm->availabil => coded = json_decode($sourcecm->availabil`
- 301 CastInt (ignoriert): `f::completion_label((int) ($sourcechild['e'] ?? 1)); => f::completion_label($sourcechild['e'] ?? 1);`
- 304 CastInt (ignoriert): `emodule_from_id('', (int) $sourcechild['cm'], => emodule_from_id('', $sourcechild['cm'],`
- 304 FalseValue (ignoriert): `urcechild['cm'], 0, false, IGNORE_MISSING); => urcechild['cm'], 0, true, IGNORE_MISSING);`

## Plugin/src/local_coursepilot/classes/external/compare_activity_versions.php (1 überlebt, 0 offen)

- 66 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`

## Plugin/src/local_coursepilot/classes/external/compose_material_file.php (1 überlebt, 0 offen)

- 117 CastObject (ignoriert): `local_coursepilot', (object) ['path' => $path, 'w => local_coursepilot', ['path' => $path, 'w`

## Plugin/src/local_coursepilot/classes/external/create_mc_question.php (9 überlebt, 0 offen)

- 106 CastInt (ignoriert): `ext::instance_by_id((int) $category->contextid => ext::instance_by_id($category->contextid`
- 116 CastInt (ignoriert): `ind_name_candidates((int) $params['categoryid' => ind_name_candidates($params['categoryid'`
- 129 CastInt (ignoriert): `'', 'categoryid' => (int) $params['categoryid' => '', 'categoryid' => $params['categoryid'`
- 139 CastInt (ignoriert): `tion_import::import((int) $params['categoryid' => tion_import::import($params['categoryid'`
- 143 CastInt (ignoriert): `st_version_question((int) $entry->id); => st_version_question($entry->id);`
- 149 CastInt (ignoriert): `tionbankentryid' => (int) $question['questionb => tionbankentryid' => $question['questionb`
- 150 CastInt (ignoriert): `yid'], 'version' => (int) $question['version'] => yid'], 'version' => $question['version']`
- 155 CastObject (ignoriert): `local_coursepilot', (object) ['name' => $params[' => local_coursepilot', ['name' => $params['`
- 179 CastFloat (ignoriert): `tionpercent = round((float) $answer['fraction'] => tionpercent = round($answer['fraction']`

## Plugin/src/local_coursepilot/classes/external/crop_material_file.php (9 überlebt, 0 offen)

- 270 FunctionCallRemoval (ignoriert): `imagedestroy($source); => `
- 307 CastObject (ignoriert): `local_coursepilot', (object) ['path' => $targetre => local_coursepilot', ['path' => $targetre`
- 421 FunctionCallRemoval (ignoriert): `imagedestroy($canvas); => `
- 445 CastInt (ignoriert): `$px0 = (int) round($x0 * $origwid => $px0 = round($x0 * $origwid`
- 446 CastInt (ignoriert): `$py0 = (int) round($y0 * $orighei => $py0 = round($y0 * $orighei`
- 447 CastInt (ignoriert): `$px1 = (int) round($x1 * $origwid => $px1 = round($x1 * $origwid`
- 448 CastInt (ignoriert): `$py1 = (int) round($y1 * $orighei => $py1 = round($y1 * $orighei`
- 455 Minus (ignoriert): `x(1, min($origwidth - $px0, $px1 - $px0)) => x(1, min($origwidth + $px0, $px1 - $px0))`
- 456 Minus (ignoriert): `(1, min($origheight - $py0, $py1 - $py0)) => (1, min($origheight + $py0, $py1 - $py0))`

## Plugin/src/local_coursepilot/classes/external/delete_material_files.php (1 überlebt, 0 offen)

- 97 CastObject (ignoriert): `local_coursepilot', (object) ['count' => count($d => local_coursepilot', ['count' => count($d`

## Plugin/src/local_coursepilot/classes/external/describe_module_fields.php (4 überlebt, 0 offen)

- 99 UnwrapTrim (ignoriert): `$modname = trim($params['modname']); => $modname = $params['modname'];`
- 120 CastBool (ignoriert): `$full = (bool) $params['full']; => $full = $params['full'];`
- 127 UnwrapArrayValues (ignoriert): `odulefields = array_values(array_filter($modulefields, static fn(field $f): bool => in_array($f->name, $commonnames, true))); => odulefields = array_filter($modulefields, static fn(field $f): bool => `
- 179 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`

## Plugin/src/local_coursepilot/classes/external/dismiss_previous_location.php (1 überlebt, 0 offen)

- 57 MethodCallRemoval (ignoriert): `self::validate_parameters(self::execute_parameters(), []); => `

## Plugin/src/local_coursepilot/classes/external/ensure_question_bank.php (3 überlebt, 0 offen)

- 103 CastInt (ignoriert): `xt_module::instance((int) $existing->id); => xt_module::instance($existing->id);`
- 111 CastInt (ignoriert): `e'], 'contextid' => (int) $bankcontext->id, 't => e'], 'contextid' => $bankcontext->id, 't`
- 131 CastInt (ignoriert): `e'], 'contextid' => (int) $bankcontext->id, 't => e'], 'contextid' => $bankcontext->id, 't`

## Plugin/src/local_coursepilot/classes/external/ensure_question_category.php (4 überlebt, 0 offen)

- 82 CastInt (ignoriert): `ext::instance_by_id((int) $parentcategory->con => ext::instance_by_id($parentcategory->con`
- 98 CastInt (ignoriert): `ent, 'contextid' => (int) $context->id, 'creat => ent, 'contextid' => $context->id, 'creat`
- 117 CastInt (ignoriert): `return ['id' => (int) $newid, 'name' => $p => return ['id' => $newid, 'name' => $p`
- 120 CastInt (ignoriert): `>id, 'contextid' => (int) $context->id, 'creat => >id, 'contextid' => $context->id, 'creat`

## Plugin/src/local_coursepilot/classes/external/ensure_quiz_question_categories.php (2 überlebt, 0 offen)

- 78 ArrayItemRemoval (ignoriert): `stion_categories', ['contextid' => $context->id]); => stion_categories', []);`
- 90 CastInt (ignoriert): `urn ['contextid' => (int) $context->id, 'defau => urn ['contextid' => $context->id, 'defau`

## Plugin/src/local_coursepilot/classes/external/ensure_section.php (3 überlebt, 0 offen)

- 96 CastString (ignoriert): `$oldname = (string) ($sectioninfo->name ?? ''); => $oldname = $sectioninfo->name ?? '';`
- 112 CastInt (ignoriert): `id, 'sectionnum' => (int) $params['sectionnum' => id, 'sectionnum' => $params['sectionnum'`
- 136 CastObject (ignoriert): `$a = (object) ['sectionnum' => $se => $a = ['sectionnum' => $se`

## Plugin/src/local_coursepilot/classes/external/export_activity_backup.php (1 überlebt, 0 offen)

- 58 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`

## Plugin/src/local_coursepilot/classes/external/export_default_activity.php (8 überlebt, 0 offen)

- 81 FalseValue (ignoriert): `odule, $course->id, false, MUST_EXIST); => odule, $course->id, true, MUST_EXIST);`
- 87 CastInt (ignoriert): `ent::discard_failed((int) $moduleinfo->coursem => ent::discard_failed($moduleinfo->coursem`
- 88 CastInt (ignoriert): `>instance) ? null : (int) $moduleinfo->instanc => >instance) ? null : $moduleinfo->instanc`
- 124 IfNegation (ignoriert): `if ($this->_form->elemen => if (!$this->_form->elemen`
- 125 MethodCallRemoval (ignoriert): `$this->_form->removeElement('visible'); => `
- 131 UnwrapArrayMerge (ignoriert): `duleinfo = (object) array_merge((array) $data, $values); => duleinfo = (object) $values;`
- 133 CastInt (ignoriert): `oduleinfo->module = (int) $module->id; => oduleinfo->module = $module->id;`
- 135 AssignCoalesce (ignoriert): `uleinfo->cmidnumber ??= ''; => uleinfo->cmidnumber = '';`

## Plugin/src/local_coursepilot/classes/external/export_questions_xml.php (3 überlebt, 0 offen)

- 98 CastInt (ignoriert): `= self::export_one((int) $questionid, $params => = self::export_one($questionid, $params`
- 165 CastString (ignoriert): `return [$xml, (string) $question->name, $fi => return [$xml, $question->name, $fi`
- 222 CastObject (ignoriert): `local_coursepilot', (object) ['name' => $entry['n => local_coursepilot', ['name' => $entry['n`

## Plugin/src/local_coursepilot/classes/external/get_course_catalog.php (19 überlebt, 0 offen)

- 99 CastInt (ignoriert): `sen', 'courseid' => (int) $params['courseid'], => sen', 'courseid' => $params['courseid'],`
- 101 CastInt (ignoriert): `=> ['sectionnum' => (int) $params['sectionnum' => => ['sectionnum' => $params['sectionnum'`
- 106 CastInt (ignoriert): `' => self::sections((int) $params['courseid'], => ' => self::sections($params['courseid'],`
- 107 CastInt (ignoriert): `params['courseid'], (int) $params['sectionnum' => params['courseid'], $params['sectionnum'`
- 147 CastString (ignoriert): `self::content_field((string) ($section->summary ?? ''), $fullcontent), 'vi => self::content_field($section->summary ?? '', $fullcontent), 'vi`
- 149 CastString (ignoriert): `y_privacy::sanitize((string) ($section->availability ?? '')), 'modules' => self => y_privacy::sanitize($section->availability ?? ''), 'modules' => self`
- 150 CastInt (ignoriert): `s' => self::modules((int) $section->id, $modul => s' => self::modules($section->id, $modul`
- 185 UnwrapArrayValues (ignoriert): `$rows = array_values($rows); => $rows = $rows;`
- 187 CastInt (ignoriert): `$section->sequence, (int) $a->cmid) <=> self:: => $section->sequence, $a->cmid) <=> self::`
- 188 CastInt (ignoriert): `$section->sequence, (int) $b->cmid); => $section->sequence, $b->cmid);`
- 193 CastString (ignoriert): `elf::module_details((string) $row->modname, (int) => elf::module_details($row->modname, (int)`
- 193 CastInt (ignoriert): `ing) $row->modname, (int) $row->instance, (int => ing) $row->modname, $row->instance, (int`
- 193 CastInt (ignoriert): `nt) $row->instance, (int) $row->cmid, $fullcon => nt) $row->instance, $row->cmid, $fullcon`
- 196 CastString (ignoriert): `>cmid, 'modname' => (string) $row->modname, 'name => >cmid, 'modname' => $row->modname, 'name`
- 206 CastString (ignoriert): `y_privacy::sanitize((string) ($row->availability ?? '')), 'content' => $det => y_privacy::sanitize($row->availability ?? ''), 'content' => $det`
- 210 CastInt (ignoriert): `::derive_visibility((int) $row->visible, (int) => ::derive_visibility($row->visible, (int)`
- 210 CastInt (ignoriert): `int) $row->visible, (int) $row->visibleoncours => int) $row->visible, $row->visibleoncours`
- 223 UnwrapArrayFilter (ignoriert): `array_values(array_filter(array_map('intval', explode(',', $sequence)))); => array_values(array_map('intval', explode(',', $sequence)));`
- 223 UnwrapArrayValues (ignoriert): `$ids = array_values(array_filter(array_map('intval', explode(',', $sequence)))); => $ids = array_filter(array_map('intval', explode(',', $sequence)));`

## Plugin/src/local_coursepilot/classes/external/get_modules.php (8 überlebt, 0 offen)

- 60 DecrementInteger (ignoriert): `int $sectionnum = -1): array => int $sectionnum = -2): array`
- 95 UnwrapArrayValues (ignoriert): `$rows = array_values($DB->get_records_sql($sql, $sqlparams)); => $rows = $DB->get_records_sql($sql, $sqlparams);`
- 97 CastInt (ignoriert): `ring) $a->sequence, (int) $a->cmid)] <=> [$b-> => ring) $a->sequence, $a->cmid)] <=> [$b->`
- 98 CastInt (ignoriert): `ring) $b->sequence, (int) $b->cmid)]; => ring) $b->sequence, $b->cmid)];`
- 118 CastInt (ignoriert): `::derive_visibility((int) $row->visible, (int) => ::derive_visibility($row->visible, (int)`
- 118 CastInt (ignoriert): `int) $row->visible, (int) $row->visibleoncours => int) $row->visible, $row->visibleoncours`
- 132 UnwrapArrayFilter (ignoriert): `array_values(array_filter(array_map('intval', explode(',', $sequence)))); => array_values(array_map('intval', explode(',', $sequence)));`
- 132 UnwrapArrayValues (ignoriert): `$ids = array_values(array_filter(array_map('intval', explode(',', $sequence)))); => $ids = array_filter(array_map('intval', explode(',', $sequence)));`

## Plugin/src/local_coursepilot/classes/external/get_question.php (14 überlebt, 0 offen)

- 102 CastInt (ignoriert): `_id_from_questionid((int) $params['questionid' => _id_from_questionid($params['questionid'`
- 103 CastInt (ignoriert): `:entry_id_from_name((int) $params['categoryid' => :entry_id_from_name($params['categoryid'`
- 103 CastString (ignoriert): `rams['categoryid'], (string) $params['name']); => rams['categoryid'], $params['name']);`
- 151 CastString (ignoriert): `$a->id, 'answer' => (string) $a->answer, 'fractio => $a->id, 'answer' => $a->answer, 'fractio`
- 153 CastString (ignoriert): `tion, 'feedback' => (string) $a->feedback, 'corre => tion, 'feedback' => $a->feedback, 'corre`
- 154 CastFloat (ignoriert): `dback, 'correct' => (float) $a->fraction > 0]; => dback, 'correct' => $a->fraction > 0];`
- 156 CastFloat (ignoriert): `if ((float) $a->fraction >= 1.0 => if ($a->fraction >= 1.0`
- 166 CastInt (ignoriert): `tionbankentryid' => (int) $entryid, 'categoryi => tionbankentryid' => $entryid, 'categoryi`
- 174 CastString (ignoriert): `>version, 'name' => (string) $question->name, 'qu => >version, 'name' => $question->name, 'qu`
- 175 CastString (ignoriert): `, 'questiontext' => (string) $question->questiont => , 'questiontext' => $question->questiont`
- 176 CastString (ignoriert): `generalfeedback' => (string) $question->generalfe => generalfeedback' => $question->generalfe`
- 177 CastString (ignoriert): `eedback, 'qtype' => (string) $question->qtype, 'd => eedback, 'qtype' => $question->qtype, 'd`
- 194 CastInt (ignoriert): `return $v ? (int) $v->questionbankentr => return $v ? $v->questionbankentr`
- 220 CastInt (ignoriert): `return (int) $first->entryid; => return $first->entryid;`

## Plugin/src/local_coursepilot/classes/external/get_quiz_cleanup_plan.php (6 überlebt, 0 offen)

- 78 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 85 UnwrapArrayMap (ignoriert): `$keep = array_flip(array_map('intval', $params['keep_questionbankentryids'])); => $keep = array_flip($params['keep_questionbankentryids']);`
- 105 CastInt (ignoriert): `if (isset($keep[(int) $row->questionbanken => if (isset($keep[$row->questionbanken`
- 113 CastString (ignoriert): `, 'questionname' => (string) $row->questionname, => , 'questionname' => $row->questionname,`
- 115 CastString (ignoriert): `, 'categoryname' => (string) $row->categoryname, => , 'categoryname' => $row->categoryname,`
- 122 CastString (ignoriert): `turn ['quizname' => (string) $quiz->name, 'editur => turn ['quizname' => $quiz->name, 'editur`

## Plugin/src/local_coursepilot/classes/external/get_version_info.php (8 überlebt, 0 offen)

- 70 CastInt (ignoriert): `$pluginversion = (int) $plugin->version; => $pluginversion = $plugin->version;`
- 71 CastString (ignoriert): `$pluginrelease = (string) $plugin->release; => $pluginrelease = $plugin->release;`
- 73 CastObject (ignoriert): `local_coursepilot', (object) ['moodlerelease' => => local_coursepilot', ['moodlerelease' =>`
- 80 CastInt (ignoriert): `local_coursepilot', (int) $installed); => local_coursepilot', $installed);`
- 84 CastString (ignoriert): `'moodle_release' => (string) $CFG->release, 'mood => 'moodle_release' => $CFG->release, 'mood`
- 85 CastString (ignoriert): `'moodle_version' => (string) $CFG->version, 'mood => 'moodle_version' => $CFG->version, 'mood`
- 86 CastString (ignoriert): `'moodle_branch' => (string) $CFG->branch, 'plugi => 'moodle_branch' => $CFG->branch, 'plugi`
- 89 FalseValue (ignoriert): `' => $installed === false ? 0 : (int) $insta => ' => $installed === true ? 0 : (int) $insta`

## Plugin/src/local_coursepilot/classes/external/import_questions_xml.php (1 überlebt, 0 offen)

- 100 CastInt (ignoriert): `tion_import::import((int) $params['categoryid' => tion_import::import($params['categoryid'`

## Plugin/src/local_coursepilot/classes/external/list_activity_versions.php (1 überlebt, 0 offen)

- 59 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`

## Plugin/src/local_coursepilot/classes/external/list_courses.php (3 überlebt, 0 offen)

- 55 MethodCallRemoval (ignoriert): `self::validate_parameters(self::execute_parameters(), []); => `
- 66 CastString (ignoriert): `->id, 'fullname' => (string) $course->fullname, ' => ->id, 'fullname' => $course->fullname, '`
- 67 CastString (ignoriert): `ame, 'shortname' => (string) $course->shortname, => ame, 'shortname' => $course->shortname,`

## Plugin/src/local_coursepilot/classes/external/list_skills.php (3 überlebt, 0 offen)

- 61 MethodCallRemoval (ignoriert): `self::validate_parameters(self::execute_parameters(), []); => `
- 75 FalseValue (ignoriert): `LECTION_PAGE))->out(false); => LECTION_PAGE))->out(true);`
- 85 CastInt (ignoriert): `n::open_with_access((int) $USER->id)) { => n::open_with_access($USER->id)) {`

## Plugin/src/local_coursepilot/classes/external/move_module.php (4 überlebt, 0 offen)

- 85 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 116 CastInt (ignoriert): `_placement::move_to((int) $cm->id, (int) $targ => _placement::move_to($cm->id, (int) $targ`
- 116 CastInt (ignoriert): `e_to((int) $cm->id, (int) $targetsection->id, => e_to((int) $cm->id, $targetsection->id,`
- 126 CastInt (ignoriert): `id, 'sectionnum' => (int) $params['sectionnum' => id, 'sectionnum' => $params['sectionnum'`

## Plugin/src/local_coursepilot/classes/external/move_question.php (14 überlebt, 0 offen)

- 98 CastInt (ignoriert): `ext::instance_by_id((int) $sourcecategory->con => ext::instance_by_id($sourcecategory->con`
- 102 CastInt (ignoriert): `ext::instance_by_id((int) $targetcategory->con => ext::instance_by_id($targetcategory->con`
- 103 CastInt (ignoriert): `if ((int) $targetcontext->id ! => if ($targetcontext->id !`
- 103 CastInt (ignoriert): `rgetcontext->id !== (int) $sourcecontext->id) => rgetcontext->id !== $sourcecontext->id)`
- 110 CastInt (ignoriert): `c fn($item): int => (int) $item->questionid, $ => c fn($item): int => $item->questionid, $`
- 116 CastString (ignoriert): `$idnumber = (string) ($entry->idnumber ?? ''); => $idnumber = $entry->idnumber ?? '';`
- 118 CastInt (ignoriert): `_idnumber_collision((int) $targetcategory->id, => _idnumber_collision($targetcategory->id,`
- 120 CastInt (ignoriert): `ory->id, $idnumber, (int) $entry->id); => ory->id, $idnumber, $entry->id);`
- 127 CastInt (ignoriert): `tiontext', ['id' => (int) $latestversion->ques => tiontext', ['id' => $latestversion->ques`
- 127 CastString (ignoriert): `= $latestversion ? (string) $DB->get_field('ques => = $latestversion ? $DB->get_field('ques`
- 140 CastInt (ignoriert): `esponse($collision, (int) $targetcategory->id, => esponse($collision, $targetcategory->id,`
- 163 CastString (ignoriert): `targetcategory->id, (string) $questionids[0], '/q => targetcategory->id, $questionids[0], '/q`
- 169 CastString (ignoriert): `$newidnumber = (string) ($entry->idnumber ?? ''); => $newidnumber = $entry->idnumber ?? '';`
- 182 CastInt (ignoriert): `c fn($item): int => (int) $item->questionid, $ => c fn($item): int => $item->questionid, $`

## Plugin/src/local_coursepilot/classes/external/move_section.php (3 überlebt, 0 offen)

- 112 CastInt (ignoriert): `id, 'sectionnum' => (int) $to, 'message' => "S => id, 'sectionnum' => $to, 'message' => "S`
- 122 GreaterThan (ignoriert): `stinationnum = $to > $from ? $to : $to - => stinationnum = $to >= $from ? $to : $to -`
- 131 CastInt (ignoriert): `id, 'sectionnum' => (int) $to, 'message' => "S => id, 'sectionnum' => $to, 'message' => "S`

## Plugin/src/local_coursepilot/classes/external/report_clone_lineage.php (8 überlebt, 0 offen)

- 77 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 89 CastInt (ignoriert): `::slot_lineage_rows((int) $cm->instance); => ::slot_lineage_rows($cm->instance);`
- 95 CastInt (ignoriert): `::course_of_context((int) $row->categorycontex => ::course_of_context($row->categorycontex`
- 96 DecrementInteger (ignoriert): `$entrycourseid !== 0 && $entrycourseid = => $entrycourseid !== -1 && $entrycourseid =`
- 102 CastString (ignoriert): `estionid, 'name' => (string) $row->questionname, => estionid, 'name' => $row->questionname,`
- 103 CastString (ignoriert): `name, 'idnumber' => (string) ($row->entryidnumber ?? ''), 'status' => $ownco => name, 'idnumber' => $row->entryidnumber ?? '', 'status' => $ownco`
- 127 UnwrapArrayValues (ignoriert): `return array_values($DB->get_records_sql('SELECT qs.slot, qr.questionbankentryid, qbe.idnumber AS entryidnumber, qc.contextid AS categorycontextid, qv.questionid, q.name AS questionname FROM {quiz_slo`
- 161 CastInt (ignoriert): `= $coursecontext ? (int) $coursecontext->inst => = $coursecontext ? $coursecontext->inst`

## Plugin/src/local_coursepilot/classes/external/report_loose_material_files.php (2 überlebt, 0 offen)

- 58 MethodCallRemoval (ignoriert): `self::validate_parameters(self::execute_parameters(), []); => `
- 78 IncrementInteger (ignoriert): `=> (int) floor(max(0, $now - $file['time => => (int) floor(max(1, $now - $file['time`

## Plugin/src/local_coursepilot/classes/external/set_restriction.php (11 überlebt, 0 offen)

- 133 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`
- 149 CastString (ignoriert): `$modname = (string) $cm->modname; => $modname = $cm->modname;`
- 162 CastInt (ignoriert): `lf::build_condition((int) $cm->course, $condit => lf::build_condition($cm->course, $condit`
- 168 CastString (ignoriert): `_locks($conditions, (string) ($cm->availability ?? '')), $params[learner_l => _locks($conditions, $cm->availability ?? ''), $params[learner_l`
- 172 CastInt (ignoriert): `course = get_course((int) $cm->course); => course = get_course($cm->course);`
- 179 CastInt (ignoriert): `self::read_settings((int) $cmid); => self::read_settings($cmid);`
- 186 CastInt (ignoriert): `return ['cmid' => (int) $cmid, 'modname' => => return ['cmid' => $cmid, 'modname' =>`
- 187 CastString (ignoriert): `$cmid, 'modname' => (string) $cm->modname, 'messa => $cmid, 'modname' => $cm->modname, 'messa`
- 275 FalseValue (ignoriert): `itycmid, $courseid, false, IGNORE_MISSING)) => itycmid, $courseid, true, IGNORE_MISSING))`
- 370 FalseValue (ignoriert): `$condition->cm, 0, false, MUST_EXIST); => $condition->cm, 0, true, MUST_EXIST);`
- 436 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`

## Plugin/src/local_coursepilot/classes/external/update_mc_question.php (24 überlebt, 0 offen)

- 110 CastInt (ignoriert): `$categoryid = (int) $category->id; => $categoryid = $category->id;`
- 111 CastInt (ignoriert): `question_bank_entry((int) $question->id); => question_bank_entry($question->id);`
- 248 CastString (ignoriert): `$idnumber = trim((string) ($entry->idnumber ?? '')); => $idnumber = trim($entry->idnumber ?? '');`
- 259 ConcatOperandRemoval (ignoriert): `?>\n<quiz>\n" . $xml . "\n</quiz>\n"; => ?>\n<quiz>\n" . $xml;`
- 293 CastInt (ignoriert): `st_version_question((int) $entry->id); => st_version_question($entry->id);`
- 296 CastInt (ignoriert): `ed_images($context, (int) $latest->id, $questi => ed_images($context, $latest->id, $questi`
- 316 CastInt (ignoriert): `tionbankentryid' => (int) $result['questionban => tionbankentryid' => $result['questionban`
- 317 CastInt (ignoriert): `yid'], 'version' => (int) $result['version'], => yid'], 'version' => $result['version'],`
- 368 CastString (ignoriert): `$mode = (string) $patch['selectionmod => $mode = $patch['selectionmod`
- 372 IncrementInteger (ignoriert): `mode === 'single' ? 1 : 0; => mode === 'single' ? 2 : 0;`
- 397 CastString (ignoriert): `uestion->{$field} = (string) $patch[$field]; => uestion->{$field} = $patch[$field];`
- 416 ArrayItemRemoval (ignoriert): `ass $a): array => ['answer' => $a->answer, 'fraction' => $a->fra => ass $a): array => ['fraction' => $a->fra`
- 417 ArrayItem (ignoriert): `array => ['answer' => $a->answer, 'fract => array => ['answer' > $a->answer, 'fract`
- 419 ArrayItem (ignoriert): `raction, 'feedback' => $a->feedback], arr => raction, 'feedback' > $a->feedback], arr`
- 420 CastArray (ignoriert): `back], array_values((array) $question->options-> => back], array_values($question->options->`
- 420 UnwrapArrayValues (ignoriert): `' => $a->feedback], array_values((array) $question->options->answers)); => ' => $a->feedback], (array) $question->options->answers);`
- 447 IncrementInteger (ignoriert): `object->id = -($i + 1); => object->id = -($i + 2);`
- 526 DecrementInteger (ignoriert): `n', 'questiontext', 0, $questiontextimage => n', 'questiontext', -1, $questiontextimage`
- 531 DecrementInteger (ignoriert): `, 'answerfeedback', 0, $images, $location => , 'answerfeedback', -1, $images, $location`
- 582 TrueValue (ignoriert): `ons = ['subdirs' => true, 'maxfiles' => -1, => ons = ['subdirs' => false, 'maxfiles' => -1,`
- 582 DecrementInteger (ignoriert): `> -1, 'maxbytes' => 0]; => > -1, 'maxbytes' => -1];`
- 582 ArrayItemRemoval (ignoriert): `$fileoptions = ['subdirs' => true, 'maxfiles' => -1, 'ma => $fileoptions = ['maxfiles' => -1, 'ma`
- 614 CastInt (ignoriert): `, 'answerfeedback', (int) $answer->id, $fileop => , 'answerfeedback', $answer->id, $fileop`
- 616 CastString (ignoriert): `->id, $fileoptions, (string) $answer->feedback); => ->id, $fileoptions, $answer->feedback);`

## Plugin/src/local_coursepilot/classes/external/update_question_category.php (19 überlebt, 0 offen)

- 75 DecrementInteger (ignoriert): `= '', int $parent = 0): array => = '', int $parent = -1): array`
- 85 CastInt (ignoriert): `ext::instance_by_id((int) $category->contextid => ext::instance_by_id($category->contextid`
- 100 GreaterThan (ignoriert): `$params['parent'] > 0 ? $DB->get_record => $params['parent'] >= 0 ? $DB->get_record`
- 103 CastInt (ignoriert): `ext::instance_by_id((int) $targetparent->conte => ext::instance_by_id($targetparent->conte`
- 105 CastInt (ignoriert): `if ((int) $targetcontext->id ! => if ($targetcontext->id !`
- 105 CastInt (ignoriert): `rgetcontext->id !== (int) $sourcecontext->id) => rgetcontext->id !== $sourcecontext->id)`
- 111 CastInt (ignoriert): `collect_subtree_ids((int) $category->id); => collect_subtree_ids($category->id);`
- 129 CastInt (ignoriert): `gory->contextid !== (int) $targetcontext->id | => gory->contextid !== $targetcontext->id |`
- 136 CastInt (ignoriert): `$update->id = (int) $category->id; => $update->id = $category->id;`
- 140 CastInt (ignoriert): `gory->contextid !== (int) $targetcontext->id) => gory->contextid !== $targetcontext->id)`
- 149 CastInt (ignoriert): `category_to_context((int) $category->id, (int) => category_to_context($category->id, (int)`
- 150 CastInt (ignoriert): `int) $category->id, (int) $category->contextid => int) $category->id, $category->contextid`
- 151 CastInt (ignoriert): `ategory->contextid, (int) $targetcontext->id); => ategory->contextid, $targetcontext->id);`
- 157 CastInt (ignoriert): `tion_set_references((int) $category->id, (int) => tion_set_references($category->id, (int)`
- 158 CastInt (ignoriert): `int) $category->id, (int) $category->id, (int) => int) $category->id, $category->id, (int)`
- 159 CastInt (ignoriert): `int) $category->id, (int) $category->contextid => int) $category->id, $category->contextid`
- 160 CastInt (ignoriert): `ategory->contextid, (int) $targetcontext->id); => ategory->contextid, $targetcontext->id);`
- 162 CastInt (ignoriert): `update->contextid = (int) $targetcontext->id; => update->contextid = $targetcontext->id;`
- 175 CastInt (ignoriert): `tid, 'contextid' => (int) $targetcontext->id, => tid, 'contextid' => $targetcontext->id,`

## Plugin/src/local_coursepilot/classes/external/update_quiz_settings.php (1 überlebt, 0 offen)

- 94 FalseValue (ignoriert): `$params['cmid'], 0, false, MUST_EXIST); => $params['cmid'], 0, true, MUST_EXIST);`

## Plugin/src/local_coursepilot/classes/external/update_section.php (5 überlebt, 0 offen)

- 105 CastString (ignoriert): `before = ['name' => (string) ($sectioninfo->name ?? ''), 'summary' => (stri => before = ['name' => $sectioninfo->name ?? '', 'summary' => (stri`
- 106 CastString (ignoriert): `? ''), 'summary' => (string) ($sectioninfo->summary ?? ''), 'visible' => (int) => ? ''), 'summary' => $sectioninfo->summary ?? '', 'visible' => (int)`
- 116 CastInt (ignoriert): `isible', $patch) && (int) $patch['visible'] == => isible', $patch) && $patch['visible'] ==`
- 120 CastInt (ignoriert): `id, 'sectionnum' => (int) $params['sectionnum' => id, 'sectionnum' => $params['sectionnum'`
- 171 CastString (ignoriert): `) $after->visible : (string) ($after->{$fieldname} ?? ''); => ) $after->visible : $after->{$fieldname} ?? '';`

## Plugin/src/local_coursepilot/classes/external/upload_material_file.php (1 überlebt, 0 offen)

- 102 CastObject (ignoriert): `local_coursepilot', (object) ['path' => $written[ => local_coursepilot', ['path' => $written[`

## Plugin/src/local_coursepilot/classes/external/write_context_file.php (1 überlebt, 0 offen)

- 149 CastObject (ignoriert): `local_coursepilot', (object) ['path' => $result[' => local_coursepilot', ['path' => $result['`

## Plugin/src/local_coursepilot/classes/gd_support.php (1 überlebt, 0 offen)

- 49 LogicalAnd (ignoriert): `ension_loaded('gd') && function_exists('im => ension_loaded('gd') || function_exists('im`

## Plugin/src/local_coursepilot/classes/glossary_entry_writer.php (5 überlebt, 0 offen)

- 206 CastObject (ignoriert): `'glossary_entries', (object) ['id' => $entry->id, => 'glossary_entries', ['id' => $entry->id,`
- 250 DecrementInteger (ignoriert): `d_glossary', $area, 0, $input[$field], $i => d_glossary', $area, -1, $input[$field], $i`
- 290 UnwrapArrayUnique (ignoriert): `foreach (array_unique($names) as $name) { => foreach ($names as $name) {`
- 309 CastInt (ignoriert): `$ids[] = (int) $category->id; => $ids[] = $category->id;`
- 311 UnwrapArrayValues (ignoriert): `return array_values(array_unique($ids)); => return array_unique($ids);`

## Plugin/src/local_coursepilot/classes/history/retention.php (8 überlebt, 0 offen)

- 109 UnwrapArrayValues (ignoriert): `DB->get_in_or_equal(array_values($userids)); => DB->get_in_or_equal($userids);`
- 172 DecrementInteger (ignoriert): `$cutoff], '', 'id', 0, $limit)); => $cutoff], '', 'id', -1, $limit));`
- 190 CastInt (ignoriert): `$cursor = (int) get_config('local_co => $cursor = get_config('local_co`
- 197 DecrementInteger (ignoriert): `r], 'id ASC', 'id', 0, $limit)); => r], 'id ASC', 'id', -1, $limit));`
- 224 CastInt (ignoriert): `ids) < $limit ? 0 : (int) end($fileids), 'loca => ids) < $limit ? 0 : end($fileids), 'loca`
- 243 UnwrapArrayValues (ignoriert): `DB->get_in_or_equal(array_values($versionids)); => DB->get_in_or_equal($versionids);`
- 264 UnwrapArrayValues (ignoriert): `DB->get_in_or_equal(array_values($fileids)); => DB->get_in_or_equal($fileids);`
- 271 UnwrapArrayValues (ignoriert): `DB->get_in_or_equal(array_values($orphans)); => DB->get_in_or_equal($orphans);`

## Plugin/src/local_coursepilot/classes/history/version_history.php (22 überlebt, 2 offen)

- 42 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 43 UnwrapArrayValues (ignoriert): `$records = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid], 'version ASC')); => $records = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid], 'version `
- 54 CastString (ignoriert): `$cmid, 'modname' => (string) $cm->modname, 'versi => $cmid, 'modname' => $cm->modname, 'versi`
- 71 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 77 CastString (ignoriert): `$cmid, 'modname' => (string) $cm->modname, 'befor => $cmid, 'modname' => $cm->modname, 'befor`
- 81 CastInt (ignoriert): `=> self::diff_files((int) $fromstate->id, (int => => self::diff_files($fromstate->id, (int`
- 81 CastInt (ignoriert): `nt) $fromstate->id, (int) $tostate->id), 'gap_ => nt) $fromstate->id, $tostate->id), 'gap_`
- 117 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 118 CastInt (ignoriert): `self::allowed_files((int) $record->id, (string => self::allowed_files($record->id, (string`
- 118 CastString (ignoriert): `((int) $record->id, (string) $cm->modname); => ((int) $record->id, $cm->modname);`
- 194 CastInt (ignoriert): `ties[] = ['cmid' => (int) $cmid, 'name' => $cm => ties[] = ['cmid' => $cmid, 'name' => $cm`
- 250 CastInt: `efcmid, 'userid' => (int) $record->userid, 'us => efcmid, 'userid' => $record->userid, 'us`
- 251 CastInt (ignoriert): `' => self::fullname((int) $record->userid, $la => ' => self::fullname($record->userid, $la`
- 252 CastInt: `ng), 'timestamp' => (int) $record->timecreated => ng), 'timestamp' => $record->timecreated`
- 267 FalseValue (ignoriert): `%H:%M', 99, false, false); => %H:%M', 99, false, true);`
- 272 CastObject (ignoriert): `local_coursepilot', (object) $meta, $lang); => local_coursepilot', $meta, $lang);`
- 276 CastObject (ignoriert): `local_coursepilot', (object) $meta, $lang); => local_coursepilot', $meta, $lang);`
- 304 CastInt (ignoriert): `= self::diff_files((int) $before->id, (int) $ => = self::diff_files($before->id, (int) $`
- 304 CastInt (ignoriert): `((int) $before->id, (int) $after->id); => ((int) $before->id, $after->id);`
- 363 CastString (ignoriert): `y_privacy::sanitize((string) ($state[$field] ?? '')); => y_privacy::sanitize($state[$field] ?? '');`
- 420 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 422 CastString (ignoriert): `d_files($versionid, (string) $cm->modname) as $fi => d_files($versionid, $cm->modname) as $fi`

## Plugin/src/local_coursepilot/classes/history/version_restore.php (22 überlebt, 0 offen)

- 48 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 55 CastString (ignoriert): `$modname = (string) $cm->modname; => $modname = $cm->modname;`
- 75 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`
- 77 UnwrapArrayMerge (ignoriert): `$changes = array_merge($changes, $result['changes']); => $changes = $result['changes'];`
- 87 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`
- 126 CastString (ignoriert): `$modname = (string) $cm->modname; => $modname = $cm->modname;`
- 132 CastInt (ignoriert): `n_history::files_at((int) $cm->id, $targetvers => n_history::files_at($cm->id, $targetvers`
- 139 Continue_ (ignoriert): `continue; => break;`
- 146 Continue_ (ignoriert): `// Nothing actually differs - no unnecessary write. continue; => break;`
- 155 CastInt (ignoriert): `e_pseudofield_draft((int) $cm->id, $fieldname, => e_pseudofield_draft($cm->id, $fieldname,`
- 156 UnwrapArrayMerge (ignoriert): `restoredfilenames = array_merge($restoredfilenames, $changedfilenames); => restoredfilenames = $changedfilenames;`
- 184 CastInt (ignoriert): `store($context->id, (int) $cm->id, $target->fi => store($context->id, $cm->id, $target->fi`
- 237 CastInt (ignoriert): `ory::arrangement_at((int) $cm->id, $targetvers => ory::arrangement_at($cm->id, $targetvers`
- 242 CastInt (ignoriert): `$quizid = (int) $cm->instance; => $quizid = $cm->instance;`
- 282 UnwrapArrayMerge (ignoriert): `list = array_unique(array_merge(shared_block::blocked_fields(), $catalogclass::blocklist())); => list = array_unique(shared_block::blocked_fields());`
- 282 UnwrapArrayMerge (ignoriert): `list = array_unique(array_merge(shared_block::blocked_fields(), $catalogclass::blocklist())); => list = array_unique($catalogclass::blocklist());`
- 282 UnwrapArrayUnique (ignoriert): `$blocklist = array_unique(array_merge(shared_block::blocked_fields(), $catalogclass::blocklist())); => $blocklist = array_merge(shared_block::blocked_fields(), $catalogclass::blocklist());`
- 311 CastString (ignoriert): `ngs::allowed_fields((string) $before['modulename' => ngs::allowed_fields($before['modulename'`
- 314 DecrementInteger (ignoriert): `($before[$name] ?? 0) !== $newvalue) { => ($before[$name] ?? -1) !== $newvalue) {`
- 314 IncrementInteger (ignoriert): `($before[$name] ?? 0) !== $newvalue) { => ($before[$name] ?? 1) !== $newvalue) {`
- 387 CastObject (ignoriert): `local_coursepilot', (object) ['field' => $change[ => local_coursepilot', ['field' => $change[`
- 392 CastObject (ignoriert): `local_coursepilot', (object) ['version' => $targe => local_coursepilot', ['version' => $targe`

## Plugin/src/local_coursepilot/classes/history/version_source.php (3 überlebt, 0 offen)

- 87 CastString (ignoriert): `return new self((string) $record->source, $re => return new self($record->source, $re`
- 87 CastInt (ignoriert): `urcecmid !== null ? (int) $record->sourcecmid => urcecmid !== null ? $record->sourcecmid`
- 121 CastString (ignoriert): `local_coursepilot', (string) ($this->refcmid ?? '?'), $lang); => local_coursepilot', $this->refcmid ?? '?', $lang);`

## Plugin/src/local_coursepilot/classes/history/version_writer.php (19 überlebt, 0 offen)

- 132 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 137 CastInt (ignoriert): `$nextversion = (int) $DB->get_field_sql(' => $nextversion = $DB->get_field_sql('`
- 142 CastObject (ignoriert): `epilot_cm_version', (object) ['cmid' => $cm->id, => epilot_cm_version', ['cmid' => $cm->id,`
- 142 CastInt (ignoriert): `$versionid = (int) $DB->insert_record(' => $versionid = $DB->insert_record('`
- 144 CastInt (ignoriert): `->id, 'courseid' => (int) $cm->course, 'versio => ->id, 'courseid' => $cm->course, 'versio`
- 151 CastArray (ignoriert): `son' => json_encode((array) $DB->get_record('cou => son' => json_encode($DB->get_record('cou`
- 158 CastString (ignoriert): `onid, $context->id, (string) $cm->modname); => onid, $context->id, $cm->modname);`
- 185 CastInt (ignoriert): `rrangement::capture((int) $cm->instance), JSON => rrangement::capture($cm->instance), JSON`
- 214 CastString (ignoriert): `le, 'modulename' => (string) $cm->modname, 'insta => le, 'modulename' => $cm->modname, 'insta`
- 226 CastString (ignoriert): `yconditionsjson'] = (string) ($cm->availability ?? ''); => yconditionsjson'] = $cm->availability ?? '';`
- 247 ArrayItemRemoval (ignoriert): `em::fetch_all(['itemtype' => 'mod', 'itemmodule' => $cm->modn => em::fetch_all(['itemmodule' => $cm->modn`
- 251 ArrayItem (ignoriert): `nstance, 'courseid' => $cm->course]); => nstance, 'courseid' > $cm->course]);`
- 309 CastObject (ignoriert): `t_cm_version_file', (object) ['versionid' => $ver => t_cm_version_file', ['versionid' => $ver`
- 313 FalseValue (ignoriert): `ileid, 'gap' => 0], false); => ileid, 'gap' => 0], true);`
- 335 MethodCallRemoval (ignoriert): `// Share the metadata row lock with retention before deciding whether to reuse it. // Moodle transactions use READ COMMITTED: after waiting, the read sees a // cleanup deletion and recreates metadata `
- 342 CastInt (ignoriert): `return (int) $existing->id; => return $existing->id;`
- 345 CastObject (ignoriert): `ursepilot_cm_file', (object) ['pathnamehash' => $ => ursepilot_cm_file', ['pathnamehash' => $`
- 345 CastInt (ignoriert): `return (int) $DB->insert_record(' => return $DB->insert_record('`
- 354 CastString (ignoriert): `size, 'mimetype' => (string) ($file->mimetype ?? ''), 'timemodified' => => size, 'mimetype' => $file->mimetype ?? '', 'timemodified' =>`

## Plugin/src/local_coursepilot/classes/image_preview.php (2 überlebt, 0 offen)

- 67 FunctionCallRemoval (ignoriert): `imagedestroy($source); => `
- 72 FunctionCallRemoval (ignoriert): `imagedestroy($canvas); => `

## Plugin/src/local_coursepilot/classes/instance_check.php (2 überlebt, 0 offen)

- 128 CastInt (ignoriert): `et_errno() ? null : (int) ($info['http_code'] ?? 0); => et_errno() ? null : $info['http_code'] ?? 0;`
- 137 CastString (ignoriert): `evaluate($httpcode, (string) $body, $url); => evaluate($httpcode, $body, $url);`

## Plugin/src/local_coursepilot/classes/location_selection.php (37 überlebt, 0 offen)

- 172 CastInt (ignoriert): `:has_supported_auth((int) $r->id); => :has_supported_auth($r->id);`
- 175 CastString (ignoriert): `) $r->id, 'name' => (string) $r->name, 'selectabl => ) $r->id, 'name' => $r->name, 'selectabl`
- 208 CastString (ignoriert): `return trim((string) (get_config('local_coursepilot', 'webdavhint') ?: '')); => return trim(get_config('local_coursepilot', 'webdavhint') ?: '');`
- 237 UnwrapArrayValues (ignoriert): `$folders = array_values(array_map(static fn(array $entry): array => ['name' => $entry['name']], array_filter($raw, static fn(array $entry): bool => $entry['type'] === 'folder'))); => $folders = array_`
- 246 UnwrapArrayValues (ignoriert): `$names = array_values(array_map(static fn(array $entry): string => $entry['name'], $raw)); => $names = array_map(static fn(array $entry): string => $entry['name'], $raw);`
- 296 CastString (ignoriert): `g_match('/\.md$/i', (string) ($entry['name'] ?? '')) === 1) { => g_match('/\.md$/i', $entry['name'] ?? '') === 1) {`
- 351 CastString (ignoriert): `data_hosts::allowed((string) ($value['fingerprint']['server'] ?? '')); => data_hosts::allowed($value['fingerprint']['server'] ?? '');`
- 415 FalseValue (ignoriert): `if ($content !== false) { => if ($content !== true) {`
- 436 ArrayItemRemoval (ignoriert): `ection[$target] ?? ['type' => pointer_location::MOODLE]); => ection[$target] ?? []);`
- 468 CastInt (ignoriert): `f::ensure_directory((int) $wanted[$target]['in => f::ensure_directory($wanted[$target]['in`
- 468 CastString (ignoriert): `get]['instanceid'], (string) $wanted[$target]['pa => get]['instanceid'], $wanted[$target]['pa`
- 539 CastString (ignoriert): `ectory = '/' . trim((string) $old['path'], '/') . => ectory = '/' . trim($old['path'], '/') .`
- 539 UnwrapTrim (ignoriert): `$directory = '/' . trim((string) $old['path'], '/') . '/'; => $directory = '/' . (string) $old['path'] . '/';`
- 539 ConcatOperandRemoval (ignoriert): `) $old['path'], '/') . '/'; => ) $old['path'], '/');`
- 544 CastString (ignoriert): `::validate_segments((string) $old['path'])); => ::validate_segments($old['path']));`
- 545 CastInt (ignoriert): `::fetch_raw_entries((int) $old['instanceid'], => ::fetch_raw_entries($old['instanceid'],`
- 592 CastString (ignoriert): `DLE, 'path' => trim((string) $location->path, '/' => DLE, 'path' => trim($location->path, '/'`
- 596 CastInt (ignoriert): `AL, 'instanceid' => (int) $location->instancei => AL, 'instanceid' => $location->instancei`
- 597 CastString (ignoriert): `stanceid, 'path' => (string) $location->relativep => stanceid, 'path' => $location->relativep`
- 618 DecrementInteger (ignoriert): `on['instanceid'] ?? 0); => on['instanceid'] ?? -1);`
- 625 CastString (ignoriert): `::validate_segments((string) ($selection['path'] ?? '')); => ::validate_segments($selection['path'] ?? '');`
- 688 CastString (ignoriert): `$relative = (string) $wanted[$target]['pa => $relative = $wanted[$target]['pa`
- 689 CastInt (ignoriert): `::fetch_raw_entries((int) $wanted[$target]['in => ::fetch_raw_entries($wanted[$target]['in`
- 702 CastString (ignoriert): `::moodle('/' . trim((string) $value['path'], '/') => ::moodle('/' . trim($value['path'], '/')`
- 702 UnwrapTrim (ignoriert): `ation::moodle('/' . trim((string) $value['path'], '/') . '/'); => ation::moodle('/' . (string) $value['path'] . '/');`
- 702 Concat (ignoriert): `er_location::moodle('/' . trim((string) $value['path'], '/') . '/'); => er_location::moodle(trim((string) $value['path'], '/') . '/' . '/');`
- 702 ConcatOperandRemoval (ignoriert): `er_location::moodle('/' . trim((string) $value => er_location::moodle(trim((string) $value`
- 702 Concat (ignoriert): `ation::moodle('/' . trim((string) $value['path'], '/') . '/'); => ation::moodle('/' . '/' . trim((string) $value['path'], '/'));`
- 702 ConcatOperandRemoval (ignoriert): `$value['path'], '/') . '/'); => $value['path'], '/'));`
- 705 CastInt (ignoriert): `_location::external((int) $value['instanceid'] => _location::external($value['instanceid']`
- 706 CastString (ignoriert): `alue['instanceid'], (string) $value['path'], (arr => alue['instanceid'], $value['path'], (arr`
- 707 CastArray (ignoriert): `ng) $value['path'], (array) ($value['fingerprint'] ?? [])); => ng) $value['path'], $value['fingerprint'] ?? []);`
- 743 CastInt (ignoriert): `return (int) $a['instanceid'] === => return $a['instanceid'] ===`
- 743 CastInt (ignoriert): `a['instanceid'] === (int) $b['instanceid'] && => a['instanceid'] === $b['instanceid'] &&`
- 756 CastInt (ignoriert): `::describe_external((int) $value['instanceid'] => ::describe_external($value['instanceid']`
- 756 CastString (ignoriert): `alue['instanceid'], (string) $value['path']); => alue['instanceid'], $value['path']);`
- 777 CastObject (ignoriert): `local_coursepilot', (object) ['instance' => $labe => local_coursepilot', ['instance' => $labe`

## Plugin/src/local_coursepilot/classes/mark_memory.php (5 überlebt, 0 offen)

- 63 CastInt (ignoriert): `TABLE, ['userid' => (int) $USER->id, 'pathhash => TABLE, ['userid' => $USER->id, 'pathhash`
- 70 CastString (ignoriert): `$record->etag !== (string) ($etag ?? '')) { => $record->etag !== ($etag ?? '')) {`
- 74 CastBool (ignoriert): `return (bool) $record->ismarked; => return $record->ismarked;`
- 95 CastInt (ignoriert): `bject) ['userid' => (int) $USER->id, 'path' => => bject) ['userid' => $USER->id, 'path' =>`
- 104 CastInt (ignoriert): `'id', ['userid' => (int) $USER->id, 'pathhash => 'id', ['userid' => $USER->id, 'pathhash`

## Plugin/src/local_coursepilot/classes/material_composition.php (26 überlebt, 0 offen)

- 54 UnwrapFinally (ignoriert): `try { foreach ($inputs as $input) { $parts[] = self::prepare_part($input, $font); } [$width, $height] = self::dimensions($parts, $arrangement); $canvas = imagecreatetruecolor($width, $height); imagesa`
- 67 Foreach_ (ignoriert): `foreach ($parts as $part) { => foreach ([] as $part) {`
- 68 FunctionCallRemoval (ignoriert): `imagedestroy($part['image']); => `
- 71 FunctionCallRemoval (ignoriert): `imagedestroy($canvas); => `
- 96 Minus (ignoriert): `min(imagesx($image) - $x, (int) round($cr => min(imagesx($image) + $x, (int) round($cr`
- 97 Minus (ignoriert): `min(imagesy($image) - $y, (int) round($cr => min(imagesy($image) + $y, (int) round($cr`
- 100 DecrementInteger (ignoriert): `x = $text === '' ? [0, 0, 0, 0, 0, 0, 0, => x = $text === '' ? [-1, 0, 0, 0, 0, 0, 0,`
- 100 IncrementInteger (ignoriert): `x = $text === '' ? [0, 0, 0, 0, 0, 0, 0, => x = $text === '' ? [1, 0, 0, 0, 0, 0, 0,`
- 100 DecrementInteger (ignoriert): `$text === '' ? [0, 0, 0, 0, 0, 0, 0, 0] => $text === '' ? [0, -1, 0, 0, 0, 0, 0, 0]`
- 100 IncrementInteger (ignoriert): `$text === '' ? [0, 0, 0, 0, 0, 0, 0, 0] => $text === '' ? [0, 1, 0, 0, 0, 0, 0, 0]`
- 100 DecrementInteger (ignoriert): `ext === '' ? [0, 0, 0, 0, 0, 0, 0, 0] : i => ext === '' ? [0, 0, -1, 0, 0, 0, 0, 0] : i`
- 100 IncrementInteger (ignoriert): `ext === '' ? [0, 0, 0, 0, 0, 0, 0, 0] : i => ext === '' ? [0, 0, 1, 0, 0, 0, 0, 0] : i`
- 100 DecrementInteger (ignoriert): `=== '' ? [0, 0, 0, 0, 0, 0, 0, 0] : imag => === '' ? [0, 0, 0, -1, 0, 0, 0, 0] : imag`
- 100 IncrementInteger (ignoriert): `=== '' ? [0, 0, 0, 0, 0, 0, 0, 0] : imag => === '' ? [0, 0, 0, 1, 0, 0, 0, 0] : imag`
- 100 DecrementInteger (ignoriert): `= '' ? [0, 0, 0, 0, 0, 0, 0, 0] : imagett => = '' ? [0, 0, 0, 0, -1, 0, 0, 0] : imagett`
- 100 IncrementInteger (ignoriert): `= '' ? [0, 0, 0, 0, 0, 0, 0, 0] : imagett => = '' ? [0, 0, 0, 0, 1, 0, 0, 0] : imagett`
- 100 DecrementInteger (ignoriert): `' ? [0, 0, 0, 0, 0, 0, 0, 0] : imagettfbb => ' ? [0, 0, 0, 0, 0, -1, 0, 0] : imagettfbb`
- 100 IncrementInteger (ignoriert): `' ? [0, 0, 0, 0, 0, 0, 0, 0] : imagettfbb => ' ? [0, 0, 0, 0, 0, 1, 0, 0] : imagettfbb`
- 100 DecrementInteger (ignoriert): `[0, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox( => [0, 0, 0, 0, 0, 0, -1, 0] : imagettfbbox(`
- 100 IncrementInteger (ignoriert): `[0, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox( => [0, 0, 0, 0, 0, 0, 1, 0] : imagettfbbox(`
- 100 DecrementInteger (ignoriert): `, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox(sel => , 0, 0, 0, 0, 0, 0, -1] : imagettfbbox(sel`
- 100 IncrementInteger (ignoriert): `, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox(sel => , 0, 0, 0, 0, 0, 0, 1] : imagettfbbox(sel`
- 100 ArrayItemRemoval (ignoriert): `[0, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox(sel => [0, 0, 0, 0, 0, 0, 0] : imagettfbbox(sel`
- 103 DecrementInteger (ignoriert): `dth, $text === '' ? 0 : $textwidth + 2 * => dth, $text === '' ? -1 : $textwidth + 2 *`
- 103 IncrementInteger (ignoriert): `dth, $text === '' ? 0 : $textwidth + 2 * => dth, $text === '' ? 1 : $textwidth + 2 *`
- 159 CastInt (ignoriert): `$baseline = (int) round((self::HEADER_ => $baseline = round((self::HEADER_`

## Plugin/src/local_coursepilot/classes/material_files.php (7 überlebt, 0 offen)

- 548 DecrementInteger (ignoriert): `($CFG->userquota ?? 0); => ($CFG->userquota ?? -1);`
- 548 IncrementInteger (ignoriert): `($CFG->userquota ?? 0); => ($CFG->userquota ?? 1);`
- 548 CastInt (ignoriert): `$quota = (int) ($CFG->userquota ?? 0); => $quota = $CFG->userquota ?? 0;`
- 549 LessThanOrEqualTo (ignoriert): `=== null || $quota <= 0) { => === null || $quota < 0) {`
- 552 DecrementInteger (ignoriert): `emainingafter = max(0, $remaining - $addi => emainingafter = max(-1, $remaining - $addi`
- 573 DecrementInteger (ignoriert): `($CFG->maxbytes ?? 0); => ($CFG->maxbytes ?? -1);`
- 773 TrueValue (ignoriert): `row->contenthash] = true; => row->contenthash] = false;`

## Plugin/src/local_coursepilot/classes/oauth_budget.php (12 überlebt, 0 offen)

- 70 DecrementInteger (ignoriert): `$allowed ? 0 : max(1, $expires - $now); => $allowed ? 0 : max(0, $expires - $now);`
- 98 CastInt (ignoriert): `tehits === false || (int) $sitehits > $sitelim => tehits === false || $sitehits > $sitelim`
- 104 CastObject (ignoriert): `record(self::TABLE, (object) ['scope' => $scope, => record(self::TABLE, ['scope' => $scope,`
- 108 CastInt (ignoriert): `if ((int) $row->hits < $source => if ($row->hits < $source`
- 109 CastInt (ignoriert): `elf::TABLE, 'hits', (int) $row->hits + 1, ['id => elf::TABLE, 'hits', $row->hits + 1, ['id`
- 169 FalseValue (ignoriert): `et_pton($address) : false; => et_pton($address) : true;`
- 170 FalseValue (ignoriert): `if ($packed !== false && strlen($packed) => if ($packed !== true && strlen($packed)`
- 170 LogicalAnd (ignoriert): `($packed !== false && strlen($packed) === => ($packed !== false || strlen($packed) ===`
- 205 CastInt (ignoriert): `return (int) $id; => return $id;`
- 208 ArrayItemRemoval (ignoriert): `ct) ($conditions + ['hits' => 0])); => ct) ($conditions + []));`
- 208 CastObject (ignoriert): `record(self::TABLE, (object) ($conditions + ['hits' => 0])); => record(self::TABLE, $conditions + ['hits' => 0]);`
- 208 CastInt (ignoriert): `return (int) $DB->insert_record(s => return $DB->insert_record(s`

## Plugin/src/local_coursepilot/classes/oauth_cleanup.php (3 überlebt, 0 offen)

- 89 DecrementInteger (ignoriert): `', ['now' => $now], 0, $batch)); => ', ['now' => $now], -1, $batch));`
- 127 DecrementInteger (ignoriert): `TL, 'now' => $now], 0, $batch)); => TL, 'now' => $now], -1, $batch));`
- 147 DecrementInteger (ignoriert): `params, 'id', 'id', 0, $batch)); => params, 'id', 'id', -1, $batch));`

## Plugin/src/local_coursepilot/classes/observer.php (8 überlebt, 0 offen)

- 41 CastInt (ignoriert): `ion_writer::capture((int) $event->objectid, (i => ion_writer::capture($event->objectid, (i`
- 41 CastInt (ignoriert): `) $event->objectid, (int) $event->userid); => ) $event->objectid, $event->userid);`
- 51 CastInt (ignoriert): `::capture_on_update((int) $event->objectid, (i => ::capture_on_update($event->objectid, (i`
- 51 CastInt (ignoriert): `) $event->objectid, (int) $event->userid); => ) $event->objectid, $event->userid);`
- 63 CastInt (ignoriert): `retention::purge_cm((int) $event->objectid); => retention::purge_cm($event->objectid);`
- 75 CastInt (ignoriert): `ntion::purge_course((int) $event->objectid); => ntion::purge_course($event->objectid);`
- 91 CastInt (ignoriert): `$cmid = (int) $event->get_context( => $cmid = $event->get_context(`
- 92 CastInt (ignoriert): `re_on_update($cmid, (int) $event->userid); => re_on_update($cmid, $event->userid);`

## Plugin/src/local_coursepilot/classes/output/admin_connections_page.php (2 überlebt, 0 offen)

- 67 CastInt (ignoriert): `_location::describe((int) $token->userid); => _location::describe($token->userid);`
- 86 FalseValue (ignoriert): `ections.php'))->out(false), 'sesskey' => ses => ections.php'))->out(true), 'sesskey' => ses`

## Plugin/src/local_coursepilot/classes/output/authorize_page.php (2 überlebt, 0 offen)

- 67 CastInt (ignoriert): `arams['client_id'], (int) $USER->id, $params[' => arams['client_id'], $USER->id, $params['`
- 107 CastBool (ignoriert): `allowpersonaldata = (bool) get_config('local_co => allowpersonaldata = get_config('local_co`

## Plugin/src/local_coursepilot/classes/output/connections_page.php (2 überlebt, 0 offen)

- 42 CastInt (ignoriert): `ke_token($revokeid, (int) $USER->id); => ke_token($revokeid, $USER->id);`
- 46 CastInt (ignoriert): `ive_tokens_for_user((int) $USER->id), location => ive_tokens_for_user($USER->id), location`

## Plugin/src/local_coursepilot/classes/output/history_page.php (3 überlebt, 0 offen)

- 58 CastInt (ignoriert): `lf::activities_data((int) $course->id)); => lf::activities_data($course->id));`
- 72 FalseValue (ignoriert): `om_id('', $cmid, 0, false, MUST_EXIST); => om_id('', $cmid, 0, true, MUST_EXIST);`
- 181 FalseValue (ignoriert): `ity['cmid']]))->out(false)]; => ity['cmid']]))->out(true)];`

## Plugin/src/local_coursepilot/classes/output/http_endpoint.php (3 überlebt, 0 offen)

- 42 CastInt (ignoriert): `lection::page_state((int) $USER->id, $result)] => lection::page_state($USER->id, $result)]`
- 43 CastString (ignoriert): `onse(200, $headers, (string) json_encode($body)); => onse(200, $headers, json_encode($body));`
- 45 CastString (ignoriert): `onse(400, $headers, (string) json_encode(['ok' => => onse(400, $headers, json_encode(['ok' =>`

## Plugin/src/local_coursepilot/classes/output/http_response.php (1 überlebt, 0 offen)

- 56 CastString (ignoriert): `$body = (string) json_encode($respons => $body = json_encode($respons`

## Plugin/src/local_coursepilot/classes/output/location_selection.php (8 überlebt, 0 offen)

- 43 FalseValue (ignoriert): `lection.php'))->out(false), 'sesskey' => ses => lection.php'))->out(true), 'sesskey' => ses`
- 63 CastInt (ignoriert): `lection::page_state((int) $user->id); => lection::page_state($user->id);`
- 70 FalseValue (ignoriert): `_browse.php'))->out(false), 'manageinstances => _browse.php'))->out(true), 'manageinstances`
- 73 FalseValue (ignoriert): `ntext()->id]))->out(false), 'sesskey' => ses => ntext()->id]))->out(true), 'sesskey' => ses`
- 144 CastInt (ignoriert): `ection::setup_state((int) $user->id); => ection::setup_state($user->id);`
- 152 CastInt (ignoriert): `:missing_steps_text((int) $user->id), 'support => :missing_steps_text($user->id), 'support`
- 153 FalseValue (ignoriert): `ortcontact']))->out(false), 'schoolhint' => => ortcontact']))->out(true), 'schoolhint' =>`
- 207 IncrementInteger (ignoriert): `($entry['date'] ?? 0)), 'target' => get_ => ($entry['date'] ?? 1)), 'target' => get_`

## Plugin/src/local_coursepilot/classes/output/location_selection_page.php (1 überlebt, 0 offen)

- 47 CastInt (ignoriert): `ection::setup_state((int) $USER->id)['state'] => ection::setup_state($USER->id)['state']`

## Plugin/src/local_coursepilot/classes/pending_write_notice.php (1 überlebt, 0 offen)

- 196 DoWhile (ignoriert): `} while (isset($existing[$identifier])); => } while (false);`

## Plugin/src/local_coursepilot/classes/personal_data.php (3 überlebt, 0 offen)

- 41 CastBool (ignoriert): `return (bool) get_config('local_co => return get_config('local_co`
- 55 DecrementInteger (ignoriert): `*true\b/', $matches[1]); => *true\b/', $matches[0]);`
- 55 CastBool (ignoriert): `return (bool) preg_match('/persone => return preg_match('/persone`

## Plugin/src/local_coursepilot/classes/personal_data_hosts.php (2 überlebt, 0 offen)

- 65 CastString (ignoriert): `L && !self::allowed((string) ($location->fingerprint['server'] ?? ''))) { => L && !self::allowed($location->fingerprint['server'] ?? '')) {`
- 77 CastString (ignoriert): `return self::parse((string) (get_config('local_coursepilot', 'personaldatahosts') ?: '')); => return self::parse(get_config('local_coursepilot', 'personaldatahosts') ?: '');`

## Plugin/src/local_coursepilot/classes/pointer_location.php (5 überlebt, 0 offen)

- 99 CastString (ignoriert): `lf::normalised_path((string) $this->path, $subpat => lf::normalised_path($this->path, $subpat`
- 101 CastString (ignoriert): `server = strtolower((string) ($this->fingerprint['server'] ?? '')); => server = strtolower($this->fingerprint['server'] ?? '');`
- 102 CastString (ignoriert): `$account = (string) ($this->fingerprint['account'] ?? ''); => $account = $this->fingerprint['account'] ?? '';`
- 103 CastString (ignoriert): `$basepath = (string) ($this->fingerprint['basepath'] ?? ''); => $basepath = $this->fingerprint['basepath'] ?? '';`
- 104 CastString (ignoriert): `, '/') . '/' . trim((string) $this->relativepath, => , '/') . '/' . trim($this->relativepath,`

## Plugin/src/local_coursepilot/classes/pointer_writer.php (4 überlebt, 0 offen)

- 193 CastString (ignoriert): `ntpath, $operation, (string) ($location->fingerprint['server'] ?? ''), $location->instanc => ntpath, $operation, $location->fingerprint['server'] ?? '', $location->instanc`
- 226 CastString (ignoriert): `$host = (string) ($location->fingerprint['server'] ?? ''); => $host = $location->fingerprint['server'] ?? '';`
- 229 CastString (ignoriert): `$host = (string) ($e->a->server ?? ''); => $host = $e->a->server ?? '';`
- 230 CastInt (ignoriert): `stanceid ?? null) ? (int) $e->a->instanceid : => stanceid ?? null) ? $e->a->instanceid :`

## Plugin/src/local_coursepilot/classes/privacy/provider.php (7 überlebt, 0 offen)

- 318 FalseValue (ignoriert): `ext->instanceid, 0, false, MUST_EXIST)->modn => ext->instanceid, 0, true, MUST_EXIST)->modn`
- 318 CastString (ignoriert): `$modname = (string) get_coursemodule_fro => $modname = get_coursemodule_fro`
- 329 CastInt (ignoriert): `tory::allowed_files((int) $record->id, $modnam => tory::allowed_files($record->id, $modnam`
- 434 CastInt (ignoriert): `retention::purge_cm((int) $context->instanceid => retention::purge_cm($context->instanceid`
- 462 InstanceOf_ (ignoriert): `if ($context instanceof \context_user) { => if (true) {`
- 481 CastInt (ignoriert): `:purge_cm_for_users((int) $context->instanceid => :purge_cm_for_users($context->instanceid`
- 514 CastInt (ignoriert): `:purge_cm_for_users((int) $context->instanceid => :purge_cm_for_users($context->instanceid`

## Plugin/src/local_coursepilot/classes/privacy_surface.php (3 überlebt, 0 offen)

- 144 UnwrapArrayValues (ignoriert): `$allowed = array_values($allowedtools); => $allowed = $allowedtools;`
- 164 UnwrapArrayKeys (ignoriert): `_merge($registered, array_keys($allowedtools), $allowed)); => _merge($registered, $allowedtools, $allowed));`
- 164 UnwrapArrayMerge (ignoriert): `ames = array_unique(array_merge($registered, array_keys($allowedtools), $allowed)); => ames = array_unique($registered);`

## Plugin/src/local_coursepilot/classes/private_files_storage_port.php (4 überlebt, 0 offen)

- 273 DecrementInteger (ignoriert): `($CFG->userquota ?? 0); => ($CFG->userquota ?? -1);`
- 273 CastInt (ignoriert): `$quota = (int) ($CFG->userquota ?? 0); => $quota = $CFG->userquota ?? 0;`
- 277 CastInt (ignoriert): `urn max(0, $quota - (int) file_get_user_used_s => urn max(0, $quota - file_get_user_used_s`
- 296 CastObject (ignoriert): `l_coursepilot', '', (object) ['remaining' => form => l_coursepilot', '', ['remaining' => form`

## Plugin/src/local_coursepilot/classes/question_bank_context.php (1 überlebt, 0 offen)

- 64 CastInt (ignoriert): `xt_module::instance((int) $bankrecord->id); => xt_module::instance($bankrecord->id);`

## Plugin/src/local_coursepilot/classes/question_import.php (67 überlebt, 0 offen)

- 64 CastInt (ignoriert): `ext::instance_by_id((int) $category->contextid => ext::instance_by_id($category->contextid`
- 141 LessThanOrEqualTo (ignoriert): `if ($maxbytes <= 0 || $bytes <= $max => if ($maxbytes < 0 || $bytes <= $max`
- 305 MethodCallRemoval (ignoriert): `$qformat->setCategory($category); => `
- 306 ArrayItemRemoval (ignoriert): `ormat->setContexts([$context]); => ormat->setContexts([]);`
- 306 MethodCallRemoval (ignoriert): `$qformat->setContexts([$context]); => `
- 307 TrueValue (ignoriert): `mat->setStoponerror(true); => mat->setStoponerror(false);`
- 307 MethodCallRemoval (ignoriert): `$qformat->setStoponerror(true); => `
- 308 MethodCallRemoval (ignoriert): `$qformat->setMatchgrades('grade'); => `
- 309 FalseValue (ignoriert): `mat->setCatfromfile(false); => mat->setCatfromfile(true);`
- 309 MethodCallRemoval (ignoriert): `$qformat->setCatfromfile(false); => `
- 310 FalseValue (ignoriert): `>setContextfromfile(false); => >setContextfromfile(true);`
- 310 MethodCallRemoval (ignoriert): `$qformat->setContextfromfile(false); => `
- 322 CastString (ignoriert): `t = trim(strip_tags((string) ob_get_clean())); => t = trim(strip_tags(ob_get_clean()));`
- 324 FalseValue (ignoriert): `if ($questions === false || $qformat->impor => if ($questions === true || $qformat->impor`
- 330 CastArray (ignoriert): `values(array_filter((array) $questions, static f => values(array_filter($questions, static f`
- 330 UnwrapArrayValues (ignoriert): `$questions = array_values(array_filter((array) $questions, static function ($question) { return !isset($question->qtype) || $question->qtype !== 'category'; })); => $questions = array_filter((array) $`
- 358 CastString (ignoriert): `$name = (string) ($question->name ?? ''); => $name = $question->name ?? '';`
- 359 CastString (ignoriert): `$xmlidnumber = trim((string) ($question->idnumber ?? '')); => $xmlidnumber = trim($question->idnumber ?? '');`
- 376 CastInt (ignoriert): `st_version_question((int) $entry->id); => st_version_question($entry->id);`
- 377 CastInt (ignoriert): `context, $question, (int) $latest->id, $xmlidn => context, $question, $latest->id, $xmlidn`
- 408 CastInt (ignoriert): `ind_name_candidates((int) $category->id, $name => ind_name_candidates($category->id, $name`
- 448 ConcatOperandRemoval (ignoriert): `$category->id . ',' . $context->id; => $category->id . ',';`
- 460 DecrementInteger (ignoriert): `estiontextitemid ?? 0); => estiontextitemid ?? -1);`
- 465 DecrementInteger (ignoriert): `alfeedbackitemid ?? 0); => alfeedbackitemid ?? -1);`
- 554 CastInt (ignoriert): `loaded->contextid = (int) $context->id; => loaded->contextid = $context->id;`
- 555 CastInt (ignoriert): `question_bank_entry((int) $reloaded->id); => question_bank_entry($reloaded->id);`
- 559 MethodCallRemoval (ignoriert): `$qformat->setCategory($category); => `
- 560 ArrayItemRemoval (ignoriert): `ormat->setContexts([$context]); => ormat->setContexts([]);`
- 560 MethodCallRemoval (ignoriert): `$qformat->setContexts([$context]); => `
- 566 ConcatOperandRemoval (ignoriert): `?>\n<quiz>\n" . $xml . "\n</quiz>"; => ?>\n<quiz>\n" . $xml;`
- 579 MethodCallRemoval (ignoriert): `$reparser->setCategory($category); => `
- 580 ArrayItemRemoval (ignoriert): `arser->setContexts([$context]); => arser->setContexts([]);`
- 580 MethodCallRemoval (ignoriert): `$reparser->setContexts([$context]); => `
- 581 TrueValue (ignoriert): `ser->setStoponerror(true); => ser->setStoponerror(false);`
- 581 MethodCallRemoval (ignoriert): `$reparser->setStoponerror(true); => `
- 582 MethodCallRemoval (ignoriert): `$reparser->setMatchgrades('grade'); => `
- 583 FalseValue (ignoriert): `ser->setCatfromfile(false); => ser->setCatfromfile(true);`
- 583 MethodCallRemoval (ignoriert): `$reparser->setCatfromfile(false); => `
- 584 FalseValue (ignoriert): `>setContextfromfile(false); => >setContextfromfile(true);`
- 584 MethodCallRemoval (ignoriert): `$reparser->setContextfromfile(false); => `
- 593 CastString (ignoriert): `t = trim(strip_tags((string) ob_get_clean())); => t = trim(strip_tags(ob_get_clean()));`
- 595 FalseValue (ignoriert): `if ($reparsed === false || $reparser->impo => if ($reparsed === true || $reparser->impo`
- 634 CastString (ignoriert): `if (trim((string) ($expected->name ?? '')) !== trim((string) => if (trim($expected->name ?? '') !== trim((string)`
- 634 UnwrapTrim (ignoriert): `if (trim((string) ($expected->name ?? '')) !== trim((string) => if ((string) ($expected->name ?? '') !== trim((string)`
- 634 CastString (ignoriert): `me ?? '')) !== trim((string) ($actual->name ?? ''))) { => me ?? '')) !== trim($actual->name ?? '')) {`
- 634 UnwrapTrim (ignoriert): `d->name ?? '')) !== trim((string) ($actual->name ?? ''))) { => d->name ?? '')) !== (string) ($actual->name ?? '')) {`
- 637 CastString (ignoriert): `edidnumber !== trim((string) ($actual->idnumber ?? ''))) { => edidnumber !== trim($actual->idnumber ?? '')) {`
- 637 UnwrapTrim (ignoriert): `xpectedidnumber !== trim((string) ($actual->idnumber ?? ''))) { => xpectedidnumber !== (string) ($actual->idnumber ?? '')) {`
- 667 GreaterThan (ignoriert): `other['fraction']) > 1.0E-5 || $answer[' => other['fraction']) >= 1.0E-5 || $answer['`
- 693 DecrementInteger (ignoriert): `qo->fraction[$i] ?? 0), 'feedback' => sel => qo->fraction[$i] ?? -1), 'feedback' => sel`
- 693 IncrementInteger (ignoriert): `qo->fraction[$i] ?? 0), 'feedback' => sel => qo->fraction[$i] ?? 1), 'feedback' => sel`
- 693 Coalesce (ignoriert): `action' => (float) ($qo->fraction[$i] ?? 0), 'feedback' => sel => action' => (float) (0 ?? $qo->fraction[$i]), 'feedback' => sel`
- 693 CastFloat (ignoriert): `wer), 'fraction' => (float) ($qo->fraction[$i] ?? 0), 'feedback' => self => wer), 'fraction' => $qo->fraction[$i] ?? 0, 'feedback' => self`
- 719 OneZeroFloat (ignoriert): `n' => $qo->answer ? 1.0 : 0.0, 'feedback' => n' => $qo->answer ? 0.0 : 0.0, 'feedback'`
- 719 OneZeroFloat (ignoriert): `$qo->answer ? 1.0 : 0.0, 'feedback' => se => $qo->answer ? 1.0 : 1.0, 'feedback' => se`
- 719 Ternary (ignoriert): `n' => $qo->answer ? 1.0 : 0.0, 'feedback' => se => n' => $qo->answer ? 0.0 : 1.0, 'feedback' => se`
- 724 OneZeroFloat (ignoriert): `n' => $qo->answer ? 0.0 : 1.0, 'feedback' => n' => $qo->answer ? 1.0 : 1.0, 'feedback'`
- 724 OneZeroFloat (ignoriert): `$qo->answer ? 0.0 : 1.0, 'feedback' => se => $qo->answer ? 0.0 : 0.0, 'feedback' => se`
- 724 Ternary (ignoriert): `n' => $qo->answer ? 0.0 : 1.0, 'feedback' => se => n' => $qo->answer ? 1.0 : 0.0, 'feedback' => se`
- 743 CastObject (ignoriert): `local_coursepilot', (object) ['name' => $name, 'v => local_coursepilot', ['name' => $name, 'v`
- 744 CastObject (ignoriert): `local_coursepilot', (object) ['name' => $name, 'v => local_coursepilot', ['name' => $name, 'v`
- 765 CastString (ignoriert): `return (string) ($value['text'] ?? ''); => return $value['text'] ?? '';`
- 767 LogicalAnd (ignoriert): `(is_object($value) && isset($value->text) => (is_object($value) || isset($value->text)`
- 767 LogicalAndSingleSubExprNegation (ignoriert): `if (is_object($value) && => if (!is_object($value) &&`
- 770 CastString (ignoriert): `return (string) $value; => return $value;`
- 781 DecrementInteger (ignoriert): `rmat, int $itemid = 0): array => rmat, int $itemid = -1): array`
- 781 IncrementInteger (ignoriert): `rmat, int $itemid = 0): array => rmat, int $itemid = 1): array`

## Plugin/src/local_coursepilot/classes/question_suspect_gate.php (4 überlebt, 0 offen)

- 132 DecrementInteger (ignoriert): `> $excludeentryid], 0, 1); => > $excludeentryid], -1, 1);`
- 182 CastInt (ignoriert): `st_version_question((int) $row->entryid); => st_version_question($row->entryid);`
- 185 CastString (ignoriert): `test->id, 'name' => (string) $latest->name, 'idnu => test->id, 'name' => $latest->name, 'idnu`
- 186 CastString (ignoriert): `name, 'idnumber' => (string) ($row->idnumber ?? '')]; => name, 'idnumber' => $row->idnumber ?? ''];`

## Plugin/src/local_coursepilot/classes/question_xml.php (8 überlebt, 0 offen)

- 54 CastFloat (ignoriert): `atic fn($answer) => (float) $answer['fraction'] => atic fn($answer) => $answer['fraction']`
- 62 CastFloat (ignoriert): `answer) => max(0.0, (float) $answer['fraction']) => answer) => max(0.0, $answer['fraction'])`
- 65 GreaterThan (ignoriert): `positivesum - 1.0) > 0.001) { => positivesum - 1.0) >= 0.001) {`
- 124 CastInt (ignoriert): `ext::instance_by_id((int) $category->contextid => ext::instance_by_id($category->contextid`
- 133 CastInt (ignoriert): `estion->contextid = (int) $context->id; => estion->contextid = $context->id;`
- 158 MethodCallRemoval (ignoriert): `$qformat->setCategory($category); => `
- 159 ArrayItemRemoval (ignoriert): `ormat->setContexts([$context]); => ormat->setContexts([]);`
- 159 MethodCallRemoval (ignoriert): `$qformat->setContexts([$context]); => `

## Plugin/src/local_coursepilot/classes/quiz/arrangement.php (9 überlebt, 0 offen)

- 77 CastString (ignoriert): `'displaynumber' => (string) ($slot->displaynumber ?? ''), => 'displaynumber' => $slot->displaynumber ?? '',`
- 160 DecrementInteger (ignoriert): `$previousid === 0 ? 1 : $structure->get_s => $previousid === 0 ? 0 : $structure->get_s`
- 201 CastFloat (ignoriert): `if (abs((float) $slot->maxmark - $ta => if (abs($slot->maxmark - $ta`
- 204 CastString (ignoriert): `if ((string) ($slot->displaynumber => if (($slot->displaynumber`
- 233 CastString (ignoriert): `if ((string) ($currentsection->hea => if (($currentsection->hea`
- 233 CastString (ignoriert): `heading ?? '') !== (string) ($targetsection['head => heading ?? '') !== ($targetsection['head`
- 259 CastObject (ignoriert): `rd('quiz_feedback', (object) array_merge($row, [' => rd('quiz_feedback', array_merge($row, ['`
- 272 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`
- 274 BitwiseOr (ignoriert): `N_UNESCAPED_UNICODE | JSON_UNESCAPED_SLAS => N_UNESCAPED_UNICODE & JSON_UNESCAPED_SLAS`

## Plugin/src/local_coursepilot/classes/remote_access.php (3 überlebt, 0 offen)

- 43 CastInt (ignoriert): `userid = $userid ?? (int) $USER->id; => userid = $userid ?? $USER->id;`
- 99 CastInt (ignoriert): `$counts[(int) $record->id] = (obje => $counts[$record->id] = (obje`
- 123 CastString (ignoriert): `$raw = (string) get_config('local_co => $raw = get_config('local_co`

## Plugin/src/local_coursepilot/classes/skill_corpus.php (3 überlebt, 0 offen)

- 51 CastString (ignoriert): `$content = (string) file_get_contents($p => $content = file_get_contents($p`
- 135 DecrementInteger (ignoriert): `$content, 4, $end - 4)) as $line) { => $content, 4, $end - 3)) as $line) {`
- 135 Minus (ignoriert): `r($content, 4, $end - 4)) as $line) { => r($content, 4, $end + 4)) as $line) {`

## Plugin/src/local_coursepilot/classes/storage_anchor.php (3 überlebt, 0 offen)

- 216 CastInt (ignoriert): `webdav_storage_port((int) $location->instancei => webdav_storage_port($location->instancei`
- 216 CastString (ignoriert): `cation->instanceid, (string) $location->relativep => cation->instanceid, $location->relativep`
- 228 CastString (ignoriert): `$configured = trim((string) (get_config('local_coursepilot', $settingname) ?: $defaultvalue), '/'); => $configured = trim(get_config('local_coursepilot', $settingname) ?: $defaultvalue, '/');`

## Plugin/src/local_coursepilot/classes/webdav/curl_transport.php (4 überlebt, 0 offen)

- 108 CastInt (ignoriert): `new webdav_response((int) ($info['http_code'] ?? 0), $this->response_he => new webdav_response($info['http_code'] ?? 0, $this->response_he`
- 108 CastString (ignoriert): `response_headers(), (string) $raw); => response_headers(), $raw);`
- 186 CastString (ignoriert): `$headers[strtolower((string) $key)] = is_array($v => $headers[strtolower($key)] = is_array($v`
- 186 CastString (ignoriert): `is_array($value) ? (string) end($value) : (strin => is_array($value) ? end($value) : (strin`

## Plugin/src/local_coursepilot/classes/webdav/webdav_client.php (5 überlebt, 0 offen)

- 86 CastInt (ignoriert): `usleep((int) ($seconds * 1000000)); => usleep($seconds * 1000000);`
- 382 CastString (ignoriert): `is->normalised_path((string) $davresponse->href); => is->normalised_path($davresponse->href);`
- 405 CastString (ignoriert): `$lastmodifiedraw = (string) $prop->getlastmodifi => $lastmodifiedraw = $prop->getlastmodifi`
- 411 CastString (ignoriert): `lection ? 0 : (int) (string) $prop->getcontentlen => lection ? 0 : (int) $prop->getcontentlen`
- 438 FalseValue (ignoriert): `if ($mimetype === false) { => if ($mimetype === true) {`

## Plugin/src/local_coursepilot/classes/webdav/webdav_instance.php (14 überlebt, 0 offen)

- 76 CastInt (ignoriert): `self::resolve_owned((int) $location->instancei => self::resolve_owned($location->instancei`
- 78 CastInt (ignoriert): `self::fresh_options((int) $location->instancei => self::fresh_options($location->instancei`
- 126 CastInt (ignoriert): `cord->contextid !== (int) $owncontextid || \co => cord->contextid !== $owncontextid || \co`
- 135 CastInt (ignoriert): `s::enabled_for_user((int) $USER->id)) { => s::enabled_for_user($USER->id)) {`
- 177 CastString (ignoriert): `nsport(new \curl(), (string) ($options['webdav_user'] ?? ''), (string) ($options => nsport(new \curl(), $options['webdav_user'] ?? '', (string) ($options`
- 178 CastString (ignoriert): `bdav_user'] ?? ''), (string) ($options['webdav_password'] ?? '')); => bdav_user'] ?? ''), $options['webdav_password'] ?? '');`
- 216 DecrementInteger (ignoriert): `s['webdav_type'] ?? 0) === 1 && ($options => s['webdav_type'] ?? -1) === 1 && ($options`
- 244 UnwrapArrayValues (ignoriert): `$names = array_values(array_map(static fn(array $entry): string => $entry['name'], array_filter($entries, static fn(array $entry): bool => $entry['type'] === 'folder'))); => $names = array_map(static `
- 279 CastString (ignoriert): `return ['server' => (string) ($options['webdav_server'] ?? ''), 'basepath' => trim => return ['server' => $options['webdav_server'] ?? '', 'basepath' => trim`
- 280 CastString (ignoriert): `'basepath' => trim((string) ($options['webdav_path'] ?? ''), '/'), 'account' => => 'basepath' => trim($options['webdav_path'] ?? '', '/'), 'account' =>`
- 281 CastString (ignoriert): `'/'), 'account' => (string) ($options['webdav_user'] ?? '')]; => '/'), 'account' => $options['webdav_user'] ?? ''];`
- 294 CastString (ignoriert): `'basepath' => trim((string) ($fingerprint['basepath'] ?? ''), '/'), 'account' => => 'basepath' => trim($fingerprint['basepath'] ?? '', '/'), 'account' =>`
- 307 DecrementInteger (ignoriert): `s['webdav_port'] ?? 0); => s['webdav_port'] ?? -1);`
- 309 CastString (ignoriert): `$path = trim((string) ($options['webdav_path'] ?? ''), '/'); => $path = trim($options['webdav_path'] ?? '', '/');`

## Plugin/src/local_coursepilot/classes/webdav_storage_port.php (4 überlebt, 0 offen)

- 189 DecrementInteger (ignoriert): `['timemodified'] ?? 0), 'size' => $entry[ => ['timemodified'] ?? -1), 'size' => $entry[`
- 189 IncrementInteger (ignoriert): `['timemodified'] ?? 0), 'size' => $entry[ => ['timemodified'] ?? 1), 'size' => $entry[`
- 397 UnwrapArrayValues (ignoriert): `return array_values(array_filter(explode('/', trim($this->baserelativepath, '/')), static fn(string $segment): bool => $segment !== '')); => return array_filter(explode('/', trim($this->baserelativepa`
- 398 UnwrapTrim (ignoriert): `filter(explode('/', trim($this->baserelativepath, '/')), static fn(string => filter(explode('/', $this->baserelativepath), static fn(string`

## Plugin/src/local_coursepilot/classes/workbench_ticket.php (14 überlebt, 6 offen)

- 81 CastInt (ignoriert): `$record->userid = (int) $USER->id; => $record->userid = $USER->id;`
- 136 CastInt (ignoriert): `= (object) ['id' => (int) $ticket->userid]; => = (object) ['id' => $ticket->userid];`
- 147 CastString (ignoriert): `name, 'mimetype' => (string) ($file->get_mimetype() ?: 'application/octet-stream'), 'content' => $file => name, 'mimetype' => $file->get_mimetype() ?: 'application/octet-stream', 'content' => $file`
- 160 CastInt (ignoriert): `if ((int) $ticket->expires < t => if ($ticket->expires < t`
- 165 CastInt (ignoriert): `ction::access_error((int) $ticket->userid, $ti => ction::access_error($ticket->userid, $ti`
- 166 CastInt (ignoriert): `ectionid !== null ? (int) $ticket->oauthconnec => ectionid !== null ? $ticket->oauthconnec`
- 167 CastInt (ignoriert): `htokenid !== null ? (int) $ticket->oauthtokeni => htokenid !== null ? $ticket->oauthtokeni`
- 184 CastInt (ignoriert): `text_user::instance((int) $ticket->userid)->id => text_user::instance($ticket->userid)->id`
- 225 Concat: `im = hash('sha256', $tickethash . '|' . random_string(20) => im = hash('sha256', '|' . $tickethash . random_string(20)`
- 225 ConcatOperandRemoval: `im = hash('sha256', $tickethash . '|' . random_string( => im = hash('sha256', '|' . random_string(`
- 225 ConcatOperandRemoval: `256', $tickethash . '|' . random_string(20)); => 256', $tickethash . random_string(20));`
- 225 DecrementInteger: `'|' . random_string(20)); => '|' . random_string(19));`
- 225 IncrementInteger: `|' . random_string(20)); => |' . random_string(21));`
- 225 Concat: `256', $tickethash . '|' . random_string(20)); => 256', $tickethash . random_string(20) . '|');`

## Plugin/src/local_coursepilot/classes/write_gate.php (2 überlebt, 0 offen)

- 113 CastInt (ignoriert): `} else if ((int) $CFG->branch > $cata => } else if ($CFG->branch > $cata`
- 179 FalseValue (ignoriert): `if ($raw === false) { => if ($raw === true) {`

## Plugin/src/local_coursepilot/classes/xml_activity_creator.php (13 überlebt, 0 offen)

- 92 CastBool (ignoriert): `cescmid === null || (bool) get_fast_modinfo($co => cescmid === null || get_fast_modinfo($co`
- 96 FalseValue (ignoriert): `, $cmid, $courseid, false, MUST_EXIST); => , $cmid, $courseid, true, MUST_EXIST);`
- 107 MethodCallRemoval (ignoriert): `activity_file_supplement::apply($cm, $files); => `
- 112 CastInt (ignoriert): `mid, $replacescmid, (int) $USER->id); => mid, $replacescmid, $USER->id);`
- 137 CastInt (ignoriert): `ter::capture($cmid, (int) $USER->id, version_w => ter::capture($cmid, $USER->id, version_w`
- 179 ArrayItemRemoval (ignoriert): `ilot_cm_version', ['courseid' => $courseid, 'source' => version_w => ilot_cm_version', ['source' => version_w`
- 187 CastInt (ignoriert): `$next[(int) $m->cmid] = (int) $m => $next[$m->cmid] = (int) $m`
- 188 CastInt (ignoriert): `$previous[(int) $m->sourcecmid] = (i => $previous[$m->sourcecmid] = (i`
- 188 CastInt (ignoriert): `) $m->sourcecmid] = (int) $m->cmid; => ) $m->sourcecmid] = $m->cmid;`
- 208 UnwrapArrayValues (ignoriert): `return array_values(array_slice($path, 1, null, true)); => return array_slice($path, 1, null, true);`
- 266 DecrementInteger (ignoriert): `ified')->length === 0) { => ified')->length === -1) {`
- 266 Identical (ignoriert): `emodified')->length === 0) { => emodified')->length !== 0) {`
- 385 UnwrapTrim (ignoriert): `'id') && is_numeric(trim($node->textContent)); => 'id') && is_numeric($node->textContent);`
