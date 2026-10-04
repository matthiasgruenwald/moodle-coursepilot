<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Coursepilot';
$string['coursepilot:use'] = 'Use Coursepilot in a course';
$string['coursepilot:useremote'] = 'Connect an AI chat to Coursepilot (remote access)';
$string['coursepilot:viewhistory'] = 'View the change history of activities';
$string['coursepilot:restoreversion'] = 'Restore activities to an earlier version';
$string['capabilitymissing'] = 'CAPABILITY_MISSING:{$a}';

// MCP tool descriptions. These strings are part of the public tool contract.
$string['tool_list_courses'] = 'Lists the courses the calling teacher may use Coursepilot in.';
$string['tool_get_course_catalog'] = 'Reads a compact, filterable Moodle course catalog for course planning. The source is explicitly marked as read from Moodle. detail="full" returns targeted full content; "compact" returns previews. Profile-field restrictions are masked: type, field, and operator remain visible while the value is replaced. Group names are never returned; only group mode and identifiers are available. Assume grouping only when the teacher explicitly mentions it.';
$string['tool_get_modules'] = 'Lists the activities of a course or section for targeted access.';
$string['tool_get_module_settings'] = 'Reads the full current state of one activity for a subsequent settings update.';
$string['tool_list_activity_versions'] = 'Lists all recorded versions of an activity with a teacher-readable change description.';
$string['tool_compare_activity_versions'] = 'Compares two recorded versions of an activity.';
$string['tool_restore_activity_version'] = 'Restores an earlier recorded activity version by writing it forward as a new version.';
$string['tool_update_module_settings'] = 'Patches settings of an existing activity.';
$string['tool_create_module'] = 'Creates a new Moodle activity.';
$string['tool_create_quiz'] = 'Creates a Moodle quiz activity.';
$string['tool_update_quiz_settings'] = 'Patches settings of an existing Moodle quiz.';
$string['tool_set_completion'] = 'Sets completion tracking fields of an activity.';
$string['tool_set_restriction'] = 'Sets availability restrictions of an activity.';
$string['tool_ensure_section'] = 'Creates a course section when it is missing.';
$string['tool_update_section'] = 'Patches the name, summary, or visibility of a course section.';
$string['tool_move_section'] = 'Moves a course section to another position.';
$string['tool_move_module'] = 'Moves an activity to another section or position.';
$string['tool_get_sections'] = 'Lists the sections of a course for targeted access.';
$string['tool_ensure_question_bank'] = 'Creates or reuses a named question bank activity.';
$string['tool_ensure_question_category'] = 'Finds or creates a question category.';
$string['tool_update_question_category'] = 'Renames or moves a question category subtree.';
$string['tool_move_question'] = 'Moves a question and all its versions to another category.';
$string['tool_create_mc_question'] = 'Creates a multiple-choice question.';
$string['tool_update_mc_question'] = 'Updates a multiple-choice question.';
$string['tool_import_questions_xml'] = 'Imports questions from Moodle XML.';
$string['tool_export_questions_xml'] = 'Exports questions as a complete Moodle XML file.';
$string['tool_get_question_categories'] = 'Lists the question categories of a named question bank.';
$string['tool_plan_question_category_cleanup'] = 'Builds a non-destructive cleanup plan for empty leaf categories in a named question bank.';
$string['questioncategorycleanupreason'] = 'Empty leaf category with no questions or subcategories. Coursepilot does not delete it; review it manually and, if appropriate, delete it in Moodle using the link.';
$string['tool_get_question'] = 'Reads the latest version of a single question.';
$string['tool_plan_quiz_cleanup'] = 'Builds a non-destructive cleanup plan for obsolete quiz slots.';
$string['tool_add_questions_to_quiz'] = 'Appends questions to a quiz in the requested order.';
$string['tool_get_version_info'] = 'Reports Moodle and Coursepilot version information.';
$string['tool_list_context_files'] = 'Lists the calling teacher\'s Coursepilot context area.';
$string['tool_describe_module_fields'] = 'Reads the field catalog for a Moodle activity type.';
$string['tool_read_context_file'] = 'Reads one file from the calling teacher\'s Coursepilot context area.';
$string['tool_write_context_file'] = 'Creates or fully overwrites one Markdown file in the calling teacher\'s Coursepilot context area.';
$string['tool_append_context_file'] = 'Appends content to one Markdown file in the calling teacher\'s Coursepilot context area.';
$string['tool_list_material_files'] = 'Lists the calling teacher\'s Coursepilot material folder.';
$string['tool_upload_material_file'] = 'Creates or fully overwrites one file in the calling teacher\'s Coursepilot material folder.';
$string['tool_preview_material_file'] = 'Returns a preview of an image in the calling teacher\'s Coursepilot material folder.';
$string['tool_crop_material_file'] = 'Crops an image in the calling teacher\'s Coursepilot material folder.';
$string['tool_compose_material_file'] = 'Composes ordered full-resolution raster parts with optional crops and source headers into one workbench PNG. Fixed white background, blue bold left-aligned headers and spacing; image bytes remain server-side.';
$string['materialcompositionemptyparts'] = 'Provide at least one source part.';
$string['materialcompositioninvalidarrangement'] = 'Arrangement must be vertical or horizontal.';
$string['materialcompositionoutputunsupported'] = 'Target extension "{$a}" cannot hold a composition result — only PNG is supported.';
$string['invalidmateriallocation'] = 'Unknown location "{$a}" — valid values are "store" and "workbench".';
$string['materialcompositionfontmissing'] = 'Image composition requires GD with FreeType and the bundled FreeSans Bold font.';
$string['materialcompositionwritten'] = 'Saved composed PNG {$a->path} ({$a->width} × {$a->height} pixels) on the workbench.';
$string['tool_report_loose_material_files'] = 'Reports material files not used by an activity in the calling teacher\'s courses.';
$string['tool_delete_material_files'] = 'Deletes exactly the specified files from the calling teacher\'s material folder.';
$string['tool_clone_activity'] = 'Clones an activity within a course or across courses.';
$string['tool_export_activity_backup'] = 'Returns the activity XML (backup file <module>.xml, without user data) of an existing activity - a template for how that activity type is built.';
$string['tool_create_activity_from_xml'] = 'Creates an activity of a developed (not catalogued) type from an activity XML (build it from coursepilot_export_default_activity). The activity is created hidden, exported and compared with the input; on deviation it is removed again in the same call and nothing remains. Optional files name material path and declared filearea, with optional plain-text caption and location (store by default, or workbench). Installed lightboxgallery accepts gallery_images; scorm, imscp and h5pactivity remain excluded. The server copies images, sets captions and generates native thumbnails after the round trip, before visibility. A file error removes only the new hidden activity and leaves any predecessor intact. Otherwise it becomes visible unless hidden is set. Creating only, never editing. With replaces_cmid the activity supersedes an existing one of the same type: the new one is placed directly behind it, the old one is only hidden (title unchanged, nothing deleted), and the response lists the places that still point at the old one (not resolved), the newest successor if the old one was already superseded (successor_cmid; ask the teacher whether to supersede that one instead, the call is not blocked) and the number of hidden earlier versions in the chain (hidden_predecessors); with dry_run=true nothing is written or read from material paths and only these hints are returned as a plan preview. Returns the cmid and the fields Moodle filled in by itself.';
$string['defaultactivitycleanupfailed'] = 'The temporary default activity could not be fully removed. No default XML is returned; check the course before retrying.';
$string['tool_export_default_activity'] = 'Returns the activity XML of a developed (not catalogued) activity type with Moodle default values - a template for creating it from XML. This write operation creates a hidden temporary activity and removes it again before returning XML; nothing remains in the course. Use the existing plan and approval workflow.';
$string['tool_report_clone_lineage'] = 'Reports whether quiz questions are copied or still shared with their source course.';
$string['tool_list_skills'] = 'Lists the Coursepilot skill corpus shipped with the plugin. Before planning or writing, call coursepilot_list_skills first.';
$string['tool_get_skill'] = 'Delivers one Coursepilot skill corpus entry by name.';
$string['tool_dismiss_pending_entry'] = 'Explicitly discards one pending context write.';
$string['tool_create_workbench_download_links'] = 'Issues single-use download links for specified workbench files.';
$string['tool_dismiss_previous_location'] = 'Explicitly ends the calling teacher\'s legacy context holdings.';

// history.php: history page in the course navigation (#397, Spec 0015 §10.6/§10.7).
$string['historynavnode'] = 'Coursepilot: change history';
$string['historytitle'] = 'Change history';
$string['historyintro'] = 'This shows the recorded change history and lets you restore an earlier version - independent of whether a Coursepilot chat is currently running.';
$string['historynoactivities'] = 'No activity in this course has a recorded change history.';
$string['historycolversion'] = 'Version';
$string['historycoluser'] = 'User';
$string['historycoltime'] = 'Time';
$string['historycolchange'] = 'Change';
$string['historycolname'] = 'Activity';
$string['historycoltype'] = 'Type';
$string['historyview'] = 'View history';
$string['historyrestore'] = 'Restore';
$string['historyrestoreconfirm'] = 'Really restore this activity to version {$a}? The old state is written forward as the new latest version, no additional activity is created.';
$string['historydatalossconfirm'] = '{$a} Really continue and delete existing completion data?';
$string['historyquizhint'] = 'Note: on quizzes, questions always show in their latest version, no version is pinned retroactively.';
$string['historybacktolist'] = 'Back to activity list';
$string['historysourcemoodle'] = 'first recorded state';
$string['historysourcediscovered'] = 'starting state found before Coursepilot';
$string['historysourcecloned'] = 'clone of activity {$a}';
$string['historysourcefromxml'] = 'created from activity XML';
$string['historysourcesuperseded'] = 'superseded by activity {$a}';
$string['historygapnotice'] = 'The history is incomplete: quiz content beyond the arrangement, the gradebook, restoring a whole course from the recycle bin (Restore), and direct database writes are not recorded. Replaced activity files in supported fields (e.g. attachments) are an exception and are retrieved when returning to an earlier state. For activity types created from XML (e.g. book, glossary, lesson), the history records only the instance row, not the child tables (chapters, entries, pages, points). The gap is visible but cannot be closed.';
$string['historysummarymarker'] = 'Version {$a->version} ({$a->source}) - {$a->user}, {$a->time}.';
$string['historysummarychange'] = 'Version {$a->version} - {$a->user}, {$a->time}: {$a->change}.';
$string['historymorefields'] = ' and {$a} more fields';
$string['historyfieldschanged'] = '{$a} changed';
$string['historyfileadded'] = '{$a} file added';
$string['historyfilesadded'] = '{$a} files added';
$string['historyfileremoved'] = '{$a} file removed';
$string['historyfilesremoved'] = '{$a} files removed';
$string['historynochange'] = 'no content change detected';
$string['historyunknownuser'] = 'User #{$a}';


// Plugin description on the settings page (Issue #500, Spec #486 §11).
$string['settingintroheading'] = 'About Coursepilot';
$string['settingintroheading_desc'] = 'On a teacher\'s behalf, Coursepilot can send names and images from their material store to the AI. With an external storage location, context files are write-locked but not read-locked — details below and in the admin guide (`docs/admin-erstanleitung.md` in the project repository).';

// Remote access governance (#338).
$string['remoteaccessdisabled'] = 'Remote access has been temporarily disabled by the administration.';
$string['remoteaccessnotgranted'] = 'REMOTE_ACCESS_NOT_GRANTED: you are neither a member of a remote access cohort nor granted local/coursepilot:useremote through a system role. Ask your Moodle administration to grant remote access.';
$string['settingremoteaccesscohorts'] = 'Remote access cohorts';
$string['settingremoteaccesscohorts_desc'] = 'Members of the selected system cohorts may connect an AI chat to Coursepilot. Alternatively, allow local/coursepilot:useremote in a role you already assign system-wide. Neither grants any course rights. Moodle\'s capability overview does not show cohort-based access.';
$string['settingremoteaccesscohorts_members'] = '{$a->name}: {$a->members} member(s)';
$string['settingremoteaccesscohorts_missing'] = 'Selected cohort {$a} no longer exists and is ignored.';
$string['settingremoteaccessenabled'] = 'Allow remote access';
$string['settingremoteaccessenabled_desc'] = 'Kill switch: immediately blocks any further access through the MCP endpoint. Already-issued access tokens remain valid — for a security incident, also use the bulk revoke on the connections overview. The normal Moodle login is not affected by this setting.';
// Event logging (#339).
$string['settingloglevel'] = 'Logging level';
$string['settingloglevel_desc'] = 'Controls which Coursepilot accesses are logged through the Moodle events API, so they appear in the usual log reports.';
$string['loglevelnone'] = 'No logging';
$string['loglevelerrors'] = 'Writes and errors';
$string['loglevelreads'] = 'Additionally reads';
$string['loglevelall'] = 'Everything';
$string['event_tool_access_succeeded'] = 'Coursepilot tool call succeeded';
$string['event_tool_access_failed'] = 'Coursepilot access failed';

// Context area (#297, issue #343).
$string['settingcontextroot'] = 'Context area root folder';
$string['settingcontextroot_desc'] = 'Purely organisational, not a security boundary. Changes only affect newly created files. Only applies to teachers without their own context pointer — this folder is also the fixed anchor where a hand-placed context pointer is looked up.';
$string['invalidcontextpath'] = 'Invalid path.';
$string['contextfilenotfound'] = 'File not found: {$a}';
$string['contextfilelocked'] = 'File locked: {$a} — marked as containing personal data (personenbezug: true), the switch for personal context data is off.';

// Context pointer (issue #445, spec: One Storage Location #442 §2).
$string['pointerunreadable'] = 'Context pointer unreadable: {$a} does not contain a valid JSON object.';
$string['pointerincomplete'] = 'Context pointer incomplete: {$a} must contain both the "context_area" and "material_store" fields.';
$string['materialstoreincontext'] = 'The material store lies inside the context area or in the same folder — that is not allowed. Please choose a different folder for the material store or the context area on the location page.';
$string['materialpathiscontext'] = 'This path belongs to the context area, not to the material store — not reachable through the material tools. Please use list_context_files/read_context_file instead.';
$string['materialembedtoolarge'] = 'File too large to embed: {$a->size} bytes, at most {$a->max} bytes per file are allowed ($CFG->maxbytes).';
$string['pointerunreachable'] = 'Context pointer points to an unreachable location: {$a} contains an invalid path.';

// Context pointer, second edition: external locations (issue #490, spec: Context
// area and material stock in the teacher's WebDAV storage #486 §2/§3/§12). No
// message names server, path, account, or password — the storage location stays
// hidden (spec §15, secrecy test).
$string['pointerexternalnotsupported'] = 'This area is external — this operation does not yet support external locations. Please switch to "in Moodle" on the location page ({$a}), or wait for external write support to follow.';
$string['webdavinstancemissing'] = 'The context pointer refers to a WebDAV connection that no longer exists. Please set it up again on the location page ({$a}).';
$string['webdavinstanceforeign'] = 'The context pointer refers to a WebDAV connection that does not belong to you, or you are logged in as another user. Please set it up again on the location page ({$a}).';
$string['webdavnotenabled'] = 'Your school has not yet enabled external storage (WebDAV) for you. Please check the location page ({$a}).';
$string['webdavauthunsupported'] = 'This WebDAV connection no longer uses https with basic authentication — Coursepilot does not support that. Please fix it on the location page ({$a}).';
$string['webdavfingerprintchanged'] = 'The server, base path, or account of this WebDAV connection has changed. Please choose again on the location page ({$a}).';
$string['contextrootmissing'] = 'The chosen context area no longer exists at the external location (moved, deleted, or renamed). Please choose again on the location page ({$a}).';
$string['webdaviservfilesonly'] = 'On IServ, only locations below "Files/" are reachable. Please choose a folder there on the location page ({$a->page}).';
$string['webdavexternalerror'] = 'The external storage could not be read ({$a->errorclass}). This is a context gap: the journal, profiles, and plan are not readable right now - please say so explicitly to the teacher once per session and keep working without those files, instead of continuing from memory. Please try again later, or check the location page ({$a->page}).';
$string['webdavexternalerrorunclear'] = 'The external storage is briefly throttling ({$a->errorclass}) - normal on some Nextcloud instances, usually over within seconds. Please wait briefly and retry the same call yourself once before telling the teacher. If it persists, the same guidance applies as usual: keep working without those files instead of continuing from memory, and check the location page ({$a->page}).';
$string['materialexternalerror'] = 'The material collection could not be read ({$a->errorclass}). Please try again later, or check the location page ({$a->page}).';
$string['locationselectionexternalerror'] = 'The external storage is not responding right now ({$a->errorclass}). Please try again later, or check the connection\'s credentials.';
// Translated labels for webdav_error::label() (Issue #565) — the constants in
// webdav_error.php are fixed German internal identifiers for code
// comparisons, never meant for display; these strings are what actually
// ends up in {$a->errorclass} above.
$string['webdaverrorunclear'] = 'unclear/throttled';
$string['webdaverrornotfound'] = 'not found';
$string['webdaverrorauthrejected'] = 'authentication rejected';
$string['webdaverrorunreachable'] = 'unreachable';
$string['webdaverrorstoragefull'] = 'storage full';
$string['webdaverrorconflict'] = 'conflict';
$string['webdaverrorblocked'] = 'blocked';
$string['webdaverrorredirected'] = 'redirect rejected';
$string['webdavstep1instruction'] = 'The administration must enable the "WebDAV" repository type under Site administration ▸ Plugins ▸ Repositories.';
$string['webdavstep2instruction'] = 'The administration must turn on "Allow user instances" for the "WebDAV" repository type.';
$string['webdavstep3instruction'] = 'The administration must grant the teacher the "repository/webdav:view" capability in their own user context (recommended via a dedicated system role).';

// Location selection page (issue #494, spec #486 §5/§10).
$string['locationselection'] = 'Coursepilot: locations for context area and material collection';
$string['coursepilotsettingsheading'] = 'Coursepilot';
$string['locationselectiontitle'] = 'Where the context area and material stock live';
$string['locationselectionheading'] = 'Where the context area and material stock live';
$string['locationselectionintro'] = 'Choose a folder in one of your WebDAV connections for each target, or leave it in Moodle.';
$string['locationselectiontabcontextarea'] = 'Context area';
$string['locationselectiontabmaterialstore'] = 'Material stock';
$string['locationselectioncontexthint'] = 'Recommendation: a dedicated folder just for Coursepilot — not mixed in with documents you already use.';
$string['locationselectionkeepmoodle'] = 'Leave in Moodle';
$string['locationselectionchooseinstance'] = 'Choose a connection';
$string['locationselectionselected'] = 'Selected';
$string['locationselectionselectfolder'] = 'Choose this folder';
$string['locationselectionbreadcrumbroot'] = 'Root';
$string['locationselectionloading'] = 'Loading …';
$string['locationselectioncreatefolder'] = 'Create folder';
$string['locationselectionnewfoldername'] = 'New folder name';
$string['locationselectionprogresschosen'] = '{$a}';
$string['locationselectionprogressopen'] = 'still open: {$a}';
$string['locationselectionfinishbutton'] = 'Finish setup';
$string['locationselectionfinishsuccess'] = 'Saved. Changed targets: {$a}.';
$string['locationselectionfinishnochange'] = 'No change — the previous location stays.';
$string['locationselectionselectionincomplete'] = 'Please choose an answer for every target before finishing.';
$string['locationselectionselectioninvalid'] = 'Invalid selection — please choose the folder in the file window again.';
$string['locationselectiontimeouttitle'] = 'No response';
$string['locationselectiontimeouttext'] = 'The storage did not respond within 8 seconds. Nothing was saved.';
$string['locationselectionbrowseerrorheading'] = 'Error loading';
$string['locationselectionretry'] = 'Try again';
$string['locationselectioncheckcredentials'] = 'Check credentials';
$string['locationselectionlater'] = 'Later';
$string['locationselectioncurrentheading'] = 'Current location';
$string['locationselectioncurrentcontextarea'] = 'Context area: {$a}';
$string['locationselectioncurrentmaterialstore'] = 'Material stock: {$a}';
$string['locationselectionhistoryheading'] = 'Previous locations';
$string['locationselectionhistoryempty'] = 'No changes yet.';
$string['locationselectionhistorydate'] = 'Date';
$string['locationselectionhistorytarget'] = 'Target';
$string['locationselectionhistoryfrom'] = 'From';
$string['locationselectionhistoryto'] = 'To';
$string['locationselectionlocationmoodle'] = 'in Moodle ({$a})';
$string['locationselectionlocationexternal'] = '{$a->instance} / {$a->path}';
$string['locationselectionlocationexternalalroot'] = '{$a} (root)';
$string['locationselectioninstanceunknown'] = 'unknown connection';
$string['locationselectionnoinstanceheading'] = 'No WebDAV connection yet';
$string['locationselectionnoinstanceintro'] = 'Your school has enabled external storage, but you have not set up a connection yet:';
$string['locationselectionnoinstancestep1'] = '1. Open the file picker (e.g. when uploading a file), choose "WebDAV", then "Configure the repository".';
$string['locationselectionnoinstancestep2'] = '2. Enter server, path and your credentials, then save.';
$string['locationselectionnoinstancestep3'] = '3. Come back to this page — the new connection appears here automatically.';
$string['locationselectionschoolhintheading'] = 'Hint from your school';
$string['locationselectionnotenabledheading'] = 'External storage not yet enabled';
$string['locationselectionnotenabledtext'] = 'Your school has not yet enabled external storage. Until then, everything stays in Moodle.';
$string['locationselectionmissingstepsheading'] = 'Text for the administration';
$string['locationselectionmissingstepsintro'] = 'Copy this text and send it to your Moodle administration:';
$string['locationselectioncoresupportlink'] = 'Support contact of your Moodle site';

// Locks and handover (issue #497, spec #486 §5).
$string['locationselectionrootnotselectable'] = 'The root of this connection is not selectable — please choose a folder below it.';
$string['locationselectioniservfilesonly'] = 'On IServ, only locations below "Files/" are selectable.';
$string['locationselectioninstanceauthunsupported'] = 'This connection does not use https with basic authentication and is therefore not selectable.';
$string['locationselectionoverlaplocked'] = 'The material stock lies inside the context area or in the same folder — please choose a different folder.';
$string['locationselectionfolderconfirmrequired'] = 'The chosen context area folder is not empty — please explicitly confirm the handover in the file window.';
$string['locationselectionconfirmheading'] = 'Folder is not empty';
$string['locationselectionconfirmcount'] = '{$a} entries already exist here, including:';
$string['locationselectionconfirmtext'] = 'Coursepilot creates markdown files here and can overwrite markdown files with the same name. It cannot delete or move anything.';
$string['locationselectionconfirmbutton'] = 'This is my Coursepilot folder';
$string['locationselectionconfirmcancel'] = 'Cancel';
$string['settingwebdavhint'] = 'School hint (location page)';
$string['settingwebdavhint_desc'] = 'Optional free text shown to teachers without their own WebDAV connection on the location page, in addition to the three setup steps — e.g. a recommendation which cloud service the school provides.';
$string['listskillslocationselectionhint'] = 'The teacher can store the context area and material stock in their own WebDAV storage instead of Moodle — location page at {$a}.';
$string['listskillspointerbrokenhint'] = 'The context pointer is unreadable or incomplete. The teacher must complete the location choice again — location page at {$a}.';

// Legacy holdings "Altbestand" (issue #498, spec #486 §9/§10): the previous
// location after a context area location change — read-only, ends explicitly.
$string['locationselectionpreviouslocationopen'] = 'Not everything from the earlier location has been taken over yet.';
$string['listskillspreviouslocationhint'] = 'Context files still remain at the previous location of the context area. Offer to copy them — location page at {$a}.';
$string['previouslocationclosed'] = 'There is no open previous location.';
$string['previouslocationdismissed'] = 'Previous location closed — it will no longer be mentioned.';
$string['contextfilealreadyexists'] = '{$a} already exists at the new location — not overwritten (copying only creates, never overwrites).';

// Pending-write note "Ausstandsnotiz" (issue #492, ADR 0023, spec #486 §8/§10):
// never an absolute server path, username, password, HTTP code, or response
// body (secrecy test) — the raw code goes into the access log instead.
$string['pendingwritefailed'] = '{$a->path} ({$a->operation}): {$a->reason}. Not saved yet, noted (identifier {$a->identifier}). Please keep the content in the conversation, never store it anywhere else, and repeat the same call with pending_entry="{$a->identifier}" once the connection is back. Connection: {$a->target}.';
$string['pendingnotewritefailed'] = '{$a->path} ({$a->operation}) was not written, and the pending-write note could not be created either — your private files are full. Please free up space and try again, or the content will be lost.';
$string['pendingoperationcreate'] = 'create';
$string['pendingoperationoverwrite'] = 'overwrite';
$string['pendingoperationappend'] = 'append';
$string['pendingoperationunknown'] = 'unknown';
$string['pendingnotequotaexceeded'] = 'The pending-write note could not be written — there is not enough space left in your private files.';
$string['pendingunknown'] = 'No pending entry with identifier {$a}.';
$string['pendingdismissed'] = 'Discarded pending entry {$a}.';

// Writing to the context area (#408, spec 0016 §4.1).
$string['contextfilenotmarkdown'] = 'Only .md files can be written to the context area: {$a}';
$string['contextfiletoolarge'] = 'Content too large: {$a->size} bytes, at most {$a->max} bytes per write.';
$string['contextfilechanged'] = 'Not written: {$a} has changed since it was last read — please read the file again and retry.';
$string['contextfileexternalconflict'] = 'Conflict: {$a} was changed externally in the meantime — please read it again, merge the changes, and write again.';
$string['contextquotaexceeded'] = 'Not written: your file quota does not have enough room — {$a->needed} MB needed, {$a->remaining} MB left. See the location page ({$a->page}).';
$string['contextfilecreated'] = '{$a} created.';
$string['contextfileoverwritten'] = '{$a->path} overwritten (before: {$a->before} bytes, now: {$a->after} bytes).';
$string['contextfileappended'] = '{$a->path} appended (now: {$a->size} bytes in total).';
$string['contextfilerotation'] = 'The file exceeds 1 MB — rotation recommended.';

// Material folder (spec 0018 §2, issue #428).
$string['settingmaterialroot'] = 'Material folder root';
$string['settingmaterialroot_desc'] = 'Purely organisational, not a security boundary. Changes only affect newly created files. Only applies to teachers without their own context pointer.';
$string['invalidmaterialpath'] = 'Invalid path.';
$string['materialfiledisallowedtype'] = 'File type not allowed: {$a->filename} — allowed extensions: {$a->allowed}.';
$string['materialfilechanged'] = 'Not written: {$a} has changed since it was last read — please read the file again and retry.';
$string['materialfiletoolarge'] = 'File too large: {$a->size} bytes, the server allows at most {$a->max} bytes per upload (post_max_size/upload_max_filesize).';
$string['materialquotaexceeded'] = 'Not written: your file quota does not have enough room — {$a->needed} MB needed, {$a->remaining} MB left.';
$string['materialquotawarning'] = 'Note: only {$a} MB of storage left.';
$string['materialfilecreated'] = '{$a} created.';
$string['materialfileoverwritten'] = '{$a->path} overwritten (before: {$a->before} bytes, now: {$a->after} bytes).';
$string['materialfilenotfound'] = 'No material file found at "{$a}" — expected path inside the material folder. Upload it via upload_material_file first, then reference it.';
$string['materialgdmissing'] = 'Image preview and image crop are disabled on this server — the PHP GD extension is missing. Upload and embed keep working.';
$string['materialpreviewnotanimage'] = '"{$a}" is not an image file — no preview available.';
$string['materialpreviewunsupported'] = 'This file cannot be read as an image (e.g. SVG or corrupted image data) — no preview available.';
$string['invalidmaterialreferencelist'] = 'Field "{$a}" expects a list of material folder paths (JSON array), e.g. ["worksheet.pdf"].';
$string['folderfilespatchunsupported'] = 'Files cannot be added to an existing "folder" afterwards via update_module_settings (a Moodle quirk of folder_update_instance()). Create the folder with create_module and the "files" field instead, or create another folder for the extra files.';

// Storage port (Ablage-Vertrag), issue #536, spec 0021.
$string['storageconflict'] = 'Conflict: {$a} has changed since it was last read — please read it again, merge the changes, and write again.';

// Image crop (Spec 0018 §5, Issue #431).
$string['materialcropsourceunsupported'] = '"{$a}" cannot be cropped — GD is raster-only, SVG and corrupted image data are excluded.';
$string['materialcropoutputunsupported'] = 'Target extension "{$a}" cannot hold a crop result — allowed: png, jpg, jpeg, gif, webp.';
$string['materialcropinvalidcoordinates'] = 'Invalid crop: coordinates must be between 0 and 1 and describe an area greater than 0 (x0={$a->x0}, y0={$a->y0}, x1={$a->x1}, y1={$a->y1}).';
$string['materialcropcreated'] = '{$a->path} created, cropped from {$a->source} ({$a->width}×{$a->height}px).';
$string['materialcropoverwritten'] = '{$a->path} overwritten, cropped from {$a->source} ({$a->width}×{$a->height}px).';

// Cleanup: loose material files (Spec 0018 §8.2/§8.3, Issue #438).
$string['materialfilesdeleted'] = '{$a->count} file(s) deleted, {$a->freed} MB freed.';
$string['materialdeletefilenotfound'] = 'Not deleted: no material file found at "{$a}" — please check the path list (typo?).';

// One-time download link for Werkbank files (Issue #501, Spec #486 §13).
$string['workbenchticketinvalid'] = 'This download link is invalid or already used — each link is valid for one retrieval only.';
$string['workbenchticketexpired'] = 'This download link has expired — links are valid for 15 minutes.';
$string['workbenchticketconnectionrevoked'] = 'The connection that issued this download link no longer exists.';
$string['workbenchticketaccountinactive'] = 'The associated Moodle account is no longer active.';
$string['workbenchticketcontentchanged'] = 'The file has changed since this download link was issued — please request a new link.';

// Switch for personal context data (#344, ADR 0011).
$string['settingallowpersonaldata'] = 'Transfer personal context data';
$string['settingallowpersonaldata_desc'] = 'Acts on the marking (frontmatter "personenbezug: true"), not on the content. While off, files marked this way are unreadable by any read tool and appear in listings as locked, not omitted. Default: off.';

// Approved external storage for personal context data (#493, ADR 0021 §3).
$string['settingpersonaldatahosts'] = 'Approved storage for personal data';
$string['settingpersonaldatahosts_desc'] = 'A file marked "personenbezug: true" is only ever written to one of these storage locations (Private Files are always approved). One entry per line: a domain covers itself and all its subdomains, separated only at dots, no "*". Entries with only one name part are rejected on save. Empty list = Private Files only. {$a}';
$string['personaldatahostsinvalid'] = 'Invalid entry: "{$a}" — one entry must be a domain of at least two name parts (e.g. "cloud.example.org"), without "*".';
$string['contextfilehostnotallowed'] = 'File {$a}: this storage is not approved for personal data.';

// Change history: retention/deletion deadline (#387).
$string['settinghistoryretentiondays'] = 'Change history retention period (days)';
$string['settinghistoryretentiondays_desc'] = 'How long change-history states are kept per activity. A daily background task deletes older states, including those of activities that are never changed again; a write to an activity also cleans its own expired states. At least 1 day; "no limit" is not an option.';
$string['taskpurgehistory'] = 'Delete expired change history and orphaned history file metadata';

// Anonymous OAuth registration budgets (#642).
$string['settingoauthregistersitelimit'] = 'OAuth registrations per window (site)';
$string['settingoauthregistersitelimit_desc'] = 'Maximum anonymous client registrations for the whole site per window. Further requests receive HTTP 429 until the window ends; already registered clients keep working. Zero or negative values mean 1; there is no unlimited option.';
$string['settingoauthregistersourcelimit'] = 'OAuth registrations per window (per source)';
$string['settingoauthregistersourcelimit_desc'] = 'Maximum anonymous client registrations per request source and window. The source is the remote address as determined by Moodle (forwarded headers only count with a configured reverse proxy; IPv6 per /64). Hosted AI clients register from shared provider addresses, so keep this high enough for your teachers. At least 1.';
$string['settingoauthregisterwindow'] = 'OAuth registration window (seconds)';
$string['settingoauthregisterwindow_desc'] = 'Length of the registration budget window. Changing it starts new windows. Budget records are deleted within an hour after their window has ended. At least 1 second.';
$string['settingoauthcimdsitelimit'] = 'OAuth client metadata fetches per window (site)';
$string['settingoauthcimdsitelimit_desc'] = 'Maximum first-time downloads of client metadata documents (clients that identify themselves with an https URL) for the whole site per window. Further unknown clients receive HTTP 429 until the window ends without any download; already known clients keep working. A failed download is not repeated for the same URL until the current 10-minute period ends. At least 1; there is no unlimited option.';
$string['settingoauthcimdsourcelimit'] = 'OAuth client metadata fetches per window (per source)';
$string['settingoauthcimdsourcelimit_desc'] = 'Maximum first-time client metadata downloads per request source and window, with the same source rules as for registrations. At least 1.';
$string['settingoauthcimdwindow'] = 'OAuth client metadata fetch window (seconds)';
$string['settingoauthcimdwindow_desc'] = 'Length of the client metadata fetch budget window. At least 1 second.';
$string['taskoauthcleanup'] = 'Delete expired OAuth state';

$string['connections'] = 'Coursepilot connections';
$string['connectionsintro'] = 'All active remote-access connections on this site. Revoking a connection invalidates its token immediately — any further access then fails.';
$string['myconnections'] = 'My Coursepilot connections';
$string['myconnectionsintro'] = 'AI applications you have connected to Coursepilot. Revoking a connection invalidates it immediately.';
$string['connectionnoconnections'] = 'No active connections.';
$string['connectionclient'] = 'Application';
$string['connectionperson'] = 'Person';
$string['connectionsince'] = 'Connected since';
$string['connectionexpires'] = 'Access token valid until';
$string['connectionrevoke'] = 'Revoke';
$string['connectionrevokeall'] = 'Revoke all connections';
$string['connectionrevokeallconfirm'] = 'Really invalidate every issued access and refresh token? Each connection will need to be re-established afterwards.';

// surface.php.
$string['surface'] = 'Coursepilot data surface';
$string['surfaceintro'] = 'Coursepilot only exposes teacher-facing course design. This page shows the agreed surface and compares it with what is actually registered on this site.';
$string['surfaceallowed'] = 'Allowed tools';
$string['surfaceforbidden'] = 'Forbidden name parts';
$string['surfaceregistered'] = 'Actually registered web service functions';
$string['surfacestatus'] = 'Status';
$string['surfaceok'] = 'The registered surface matches the contract.';
$string['surfaceviolations'] = 'The registered surface violates the contract:';
$string['surfacecoltype'] = 'Type';
$string['surfacecolname'] = 'Name';
$string['surfacecoldetail'] = 'Detail';
$string['surfacecoltool'] = 'MCP tool';
$string['surfacecolfunction'] = 'Web service function';

// surface.php: instance check via self-fetch (#340).
$string['surfaceinstance'] = 'Instance prerequisites for remote access';
$string['surfaceinstanceintro'] = 'For AI tools to reach this instance it needs public HTTPS, egress to the provider, and working PATH_INFO. This page does not check that by looking at configuration, but by actually fetching the discovery address itself — reverse proxies and disabled PATH_INFO would otherwise only show up on a client\'s first connection attempt.';
$string['surfacereqhttps'] = 'Public HTTPS';
$string['surfacereqegress'] = 'Egress to the provider (outbound connections allowed)';
$string['surfacereqpathinfo'] = 'PATH_INFO is passed through to PHP files';
$string['selfcheckurl'] = 'Checked address: {$a}';
$string['selfcheckok'] = 'Self-check succeeded — the discovery address is reachable from this instance.';
$string['selfcheckrequireshttps'] = 'Self-check failed — the instance is not reachable over HTTPS (public HTTPS is a prerequisite).';
$string['selfcheckrequestfailed'] = 'Self-check failed — no response from the server (timeout, DNS, or TLS error).';
$string['selfcheckunexpectedstatus'] = 'Self-check failed — the discovery address did not answer with HTTP 200.';
$string['selfcheckinvalidbody'] = 'Self-check failed — the response did not contain valid discovery metadata.';
$string['surfaceinstanceemergencyexit'] = 'Emergency-exit rule as a documented deviation: the goal is zero web server intervention, but if PATH_INFO does not arrive, a web server rule such as "{$a}" (Apache) in this instance\'s virtual host helps — not the intended path, but a documented exception.';

// oauth/authorize.php, oauth/token.php (#336).
$string['authorizetitle'] = 'Connect Coursepilot';
$string['authorizetitleclient'] = 'Connect Coursepilot with {$a}';
$string['authorizeerror'] = 'Authorization error: {$a}';
$string['consentconfirm'] = 'Allow connection';
$string['consentdeny'] = 'Deny';
$string['consentintro'] = 'You are allowing <strong>{$a}</strong> to access your Moodle courses on your behalf. Your own permissions apply — you will not see anything you could not already see.';
$string['consentgranted'] = '<strong>Shared:</strong> course list, sections, activities and their content (page text, assignment instructions, questions), and your Coursepilot context files.';
$string['consentdenied'] = '<strong>Not shared:</strong> submissions, forum posts, quiz attempts, grades, participant lists.';
$string['consenttransfer'] = '<strong>Transfer to the AI provider:</strong> everything Coursepilot reads on request is transferred to and processed by the AI provider. Nothing runs in the background — only what a tool returns on request is transferred.';
$string['consentpersonaldataoff'] = 'This Moodle site transfers <strong>no</strong> context files marked as containing personal data (personenbezug: true). Such files are shown to you as locked.';
$string['consentpersonaldataon'] = 'This Moodle site <strong>also</strong> transfers context files marked as containing personal data (personenbezug: true) — for example class profiles with student names. Your school has explicitly enabled this.';
$string['consentabbreviate'] = 'What personal information you may put in context files is governed by your school and your jurisdiction\'s data protection rules. Coursepilot does not check this. Use abbreviations instead of names where that suffices for planning.';
$string['consentrevoke'] = 'You can revoke this connection at any time from your user menu → Preferences → Coursepilot → My Coursepilot connections.';

// Location choice at connection time (Issue #446, display-only since Issue #494,
// linked again since Issue #563 with a round trip back to the connection).
$string['consentlocationheading'] = 'Where your Coursepilot area lives';
$string['consentlocationintro'] = 'Your Coursepilot area lives in Moodle by default. You can move it to an external store instead — that is optional and can always be changed again later.';
$string['consentlocationcontextareacurrent'] = 'Journals and plans: {$a}';
$string['consentlocationmaterialstorecurrent'] = 'Material files: {$a}';
$string['consentlocationchangelink'] = 'Change location';
$string['consentlocationsetuplink'] = 'Set location now';
$string['locationselectionoauthflowinfo'] = 'You are setting up the connection to {$a}. Finishing here returns you to the consent page.';
$string['locationselectionoauthflowback'] = 'Back to the consent page without changing anything';

// External location privacy notice (Issue #500, ADR 0021, Spec #486 §11) -
// same wording everywhere a teacher sees it before or during an external
// location choice: consent dialog, "My connections", the "personaldatahosts"
// setting description.
$string['externallocationprivacyinfo'] = 'If the context area or material store is external: names and images from the store can be sent to the AI on request. External context files have a write lock but no read lock. Mounted shares (e.g. IServ groups, Nextcloud shares) cannot be told apart from the outside. Use an app password instead of your main password for the connection.';
$string['locationselectionallowedyes'] = 'approved storage for personal data';
$string['locationselectionallowedno'] = 'not an approved storage for personal data';

// classes/privacy/provider.php (#336).
$string['privacy:metadata:oauth_code'] = 'Short-lived, PKCE-bound authorization codes for the OAuth consent dialog.';
$string['privacy:metadata:oauth_code:clientid'] = 'The AI client id the code was issued for.';
$string['privacy:metadata:oauth_code:userid'] = 'The user id of the teacher who granted consent.';
$string['privacy:metadata:oauth_code:redirecturi'] = 'The client redirect target.';
$string['privacy:metadata:oauth_code:codechallenge'] = 'The client PKCE S256 challenge.';
$string['privacy:metadata:oauth_code:expires'] = 'Expiry time of the code.';
$string['privacy:metadata:oauth_code:used'] = 'Whether the code has already been redeemed.';
$string['privacy:metadata:oauth_token'] = 'Access and refresh tokens an AI client uses to access Coursepilot on the teacher\'s behalf.';
$string['privacy:metadata:oauth_token:clientid'] = 'The AI client id.';
$string['privacy:metadata:oauth_token:userid'] = 'The user id of the teacher the client acts on behalf of.';
$string['privacy:metadata:oauth_token:expires'] = 'Expiry time of the access token.';
$string['privacy:metadata:oauth_token:refreshexpires'] = 'Expiry time of the refresh token.';
$string['privacy:metadata:oauth_token:revoked'] = 'Whether the token has been revoked or invalidated by rotation.';
$string['privacy:metadata:oauth_token:timecreated'] = 'Issuance time.';

// classes/privacy/provider.php: context files (#343, #345).
$string['privacy:metadata:core_files'] = 'Coursepilot context files in the teacher\'s private file area.';

// classes/privacy/provider.php: change history (#385/#386/#387).
$string['privacy:metadata:cm_version'] = 'Change history of activities: a full settings snapshot per write, with the user id of the teacher who triggered the write. A daily background task deletes states older than the retention period set by the administration (setting "Change history retention period", default 1 year, minimum 1 day); states are also deleted immediately when the activity or course is deleted, and on an approved privacy request for the teacher or the activity. A privacy export contains the teacher\'s own states as metadata; the snapshot content is course design shared with other editors and is not exported.';
$string['privacy:metadata:cm_version:cmid'] = 'The activity this state belongs to.';
$string['privacy:metadata:cm_version:courseid'] = 'The course this activity belonged to at the time of the write.';
$string['privacy:metadata:cm_version:version'] = 'Sequential number of the state within the activity.';
$string['privacy:metadata:cm_version:source'] = 'Origin of the write (Moodle form, discovered starting state, clone, activity XML).';
$string['privacy:metadata:cm_version:sourcecmid'] = 'The related activity of a clone or replacement.';
$string['privacy:metadata:cm_version:moduleinfo_json'] = 'Snapshot of the activity settings and content at the time of the write.';
$string['privacy:metadata:cm_version:coursemodule_json'] = 'Snapshot of the course module record at the time of the write.';
$string['privacy:metadata:cm_version:arrangement_json'] = 'Snapshot of the quiz question arrangement at the time of the write (quizzes only).';
$string['privacy:metadata:cm_version:userid'] = 'The user id of the teacher the write ran under.';
$string['privacy:metadata:cm_version:timecreated'] = 'Time of the write.';
$string['privacy:metadata:cm_version_file'] = 'Links a history state to the files the activity had at that time (metadata only, see local_coursepilot_cm_file). Deleted along with its state.';
$string['privacy:metadata:cm_version_file:versionid'] = 'The history state this file belongs to.';
$string['privacy:metadata:cm_version_file:fileid'] = 'The referenced file metadata row (local_coursepilot_cm_file).';
$string['privacy:metadata:cm_version_file:gap'] = 'Whether the file content is outside the description and cannot be written back.';
$string['privacy:metadata:cm_file'] = 'Deduplicated file metadata (name, size, path) for the change history, without file content. Deleted as soon as no history state references it any more.';
$string['privacy:metadata:cm_file:pathnamehash'] = 'Hash of the file pathname, used for deduplication.';
$string['privacy:metadata:cm_file:contenthash'] = 'Hash of the file content, used for deduplication.';
$string['privacy:metadata:cm_file:filepath'] = 'Folder path of the file within the activity.';
$string['privacy:metadata:cm_file:filename'] = 'File name.';
$string['privacy:metadata:cm_file:filesize'] = 'File size in bytes.';
$string['privacy:metadata:cm_file:timemodified'] = 'Last modification time of the file.';
$string['privacy:metadata:context_mark'] = 'Marking memory (#493): per context file, only the "marked yes/no" bit plus the key used to detect changes (path, size, modification time, ETag) — never any file content.';
$string['privacy:metadata:context_mark:userid'] = 'The user id of the teacher this entry belongs to.';
$string['privacy:metadata:context_mark:path'] = 'Client path of the context file this entry is about.';
$string['privacy:metadata:context_mark:ismarked'] = 'Whether the file was last found to be marked as containing personal data.';
$string['privacy:metadata:workbench_ticket'] = 'One-time download ticket for a workbench file (#501): only the hash of the ticket secret is stored, never the secret itself.';
$string['privacy:metadata:workbench_ticket:userid'] = 'The user id of the teacher the ticket was issued for.';
$string['privacy:metadata:workbench_ticket:path'] = 'Path of the workbench file, relative to the workbench root.';
$string['privacy:metadata:workbench_ticket:contenthash'] = 'Content checksum of the file at issuance time.';
$string['privacy:metadata:workbench_ticket:oauthtokenid'] = 'Reference to the issuing connection (local_coursepilot_oauth_token).';
$string['privacy:metadata:workbench_ticket:expires'] = 'Expiry time of the ticket.';
$string['privacy:metadata:workbench_ticket:timecreated'] = 'Issuance time.';

// classes/privacy/provider.php: external location (#500, ADR 0021).
$string['privacy:metadata:webdav_external_storage'] = 'The context area and material store can live on the external WebDAV storage the teacher chose via the location picker - outside Moodle and outside this plugin. Coursepilot reads and writes there directly, without keeping its own copy in Moodle.';
$string['privacy:metadata:webdav_external_storage:path'] = 'The file and folder path on the external storage.';
$string['privacy:metadata:webdav_external_storage:content'] = 'The file content, including any marked personal data such as names from learning group profiles.';

// Field catalog (#379).
$string['kindexcludedfiles'] = 'This activity type needs files in its content. Creating it from XML is not available yet, because files cannot be added from the material store so far.';
$string['activityfilesunsupported'] = 'This activity type has no declared file supplement. The new activity was removed.';
$string['activityfileinvalidarea'] = 'The file area "{$a}" is not declared for this activity type. The new activity was removed.';
$string['activityfileduplicate'] = 'More than one material path targets the image filename "{$a}". Use distinct filenames. The new activity was removed.';
$string['activityfileinvalidimage'] = 'The material file "{$a}" is not a valid image. The new activity was removed.';
$string['kindexcludednobackup'] = 'This activity type does not support Moodle backup, so it cannot be created from XML.';
$string['kindexcludedquestions'] = 'This activity type contains questions and cannot be created from XML.';
$string['defaultactivitycatalogued'] = 'Activity type "{$a->modname}" is catalogued: create it with coursepilot_create_module (see coursepilot_describe_module_fields), not from XML.';
$string['unknownmodname'] = 'Unknown activity type "{$a->modname}". Coursepilot catalogs: {$a->modnames}.';

// Skill corpus: coursepilot_list_skills/coursepilot_get_skill (Spec 0020 §4, #450).
$string['unknownskillname'] = 'Unknown skill name "{$a->name}". Valid names: {$a->names}.';

// Write core: update_module_settings (#388).
$string['writevehicleblocked'] = '"{$a->modname}" is not written via update_module_settings, but via {$a->write_route}. Nothing was written.';
$string['invalidpatchjson'] = 'felder_json is not a valid JSON object. Nothing was written.';
$string['invalidfieldname'] = 'Field names in felder_json must be strings. Nothing was written.';
$string['invalideditorpseudofield'] = 'Field "{$a->field}" needs the content as text or as an object with "text" - {$a->value} was supplied. Without "text" the content would end up empty, so nothing was written.';
$string['unknownfield'] = 'Unknown field "{$a->field}" for activity type "{$a->modname}". describe_module_fields(modname: "{$a->modname}", vollstaendig: true) shows the allowed fields. Nothing was written.';
$string['blockedfield'] = 'Field "{$a->field}" is locked for activity type "{$a->modname}" and cannot be set via patch. describe_module_fields(modname: "{$a->modname}", vollstaendig: true) shows the block list. Nothing was written.';
$string['invalidfieldvalue'] = 'Invalid value "{$a->value}" for field "{$a->field}" on activity type "{$a->modname}". describe_module_fields(modname: "{$a->modname}", vollstaendig: true) shows the allowed range. Nothing was written.';
$string['combinationruleviolation'] = 'Combination rule violated for activity type "{$a->modname}": {$a->message} describe_module_fields(modname: "{$a->modname}", vollstaendig: true) shows all combination rules. Nothing was written.';

// Visibility/stealth/group mode via the shared block (#390).
$string['stealthnotallowed'] = 'Stealth ("visibleoncoursepage" = 0) is disabled on this Moodle instance (setting "allowstealth"). The activity can be hidden (visible = 0) or shown, but not made reachable while unlisted on the course page. Nothing was written.';

// Write core: create_quiz/update_quiz_settings (#398).
$string['unknownmode'] = 'Unknown mode "{$a->mode}". Allowed: {$a->modes}. Nothing was written.';
$string['invalidquizgradepass'] = 'Invalid gradepass: supply a finite JSON number in quiz grade points, NOT percent, from 0 to {$a->maximum} inclusive. 0 disables the passing threshold. Nothing was written.';
$string['quizgradepassmeaning'] = 'Passing grade in quiz grade points, NOT percent: a finite JSON number from 0 to the maximum grade (inclusive); 0 disables the passing threshold. For 80%, read the current grade with get_module_settings and supply 0.8 * grade (10 -> 8, 25 -> 20). If the grade parameter changes the maximum in the same call, use that new maximum.';
$string['quizgradepassrule'] = '"gradepass" must be a finite JSON number from 0 to the effective maximum grade inclusive, in grade points, not percent. Coursepilot enforces this range for every question behaviour. 0 disables the passing threshold; a simultaneous grade change supplies the new maximum.';
$string['quizpatchfields'] = 'JSON object field name => new value - only the fields to change (patch, not a full state). "grade"/"sumgrades" are NOT possible here (blocked), see the "grade" parameter. "gradepass" is a finite JSON number in grade points, NOT percent, from 0 to the maximum grade inclusive (0 disables the threshold). Read grade with get_module_settings: 80% of grade 10 is {"gradepass":8}, of grade 25 is {"gradepass":20}. When grade changes in this call, gradepass refers to the new maximum.';
$string['modulesettingsdescription'] = 'Current activity settings as JSON, including coursepagevisibility/availability_status; profile conditions in availabilityconditionsjson are masked (ADR 0011). For quizzes, gradepass and grademax are persisted gradebook values in grade points, not percent; null means the primary grade item is missing.';

// Write core: create_module (#389).
$string['requiredfieldwithoutdefault'] = 'These required fields for activity type "{$a->modname}" have no form default and must be supplied: {$a->field}. Nothing was created.';
$string['readonlyvocabularyfield'] = 'Field "{$a->field}" is read vocabulary of the reading tools, not a writable field for activity type "{$a->modname}". To set it, use: {$a->hint}. Nothing was written.';

// Write core: structure and positions (#391).
$string['invalidsectionnum'] = 'Invalid section number "{$a->sectionnum}". Nothing was written.';
$string['sectionnotfound'] = 'Section "{$a->sectionnum}" does not exist.';
$string['sectionunknownfield'] = 'Unknown field "{$a->field}" for sections. Allowed: {$a->fields}. Nothing was written.';
$string['sectioninvalidvisible'] = 'Invalid value "{$a->value}" for "visible" - only 0 or 1 are allowed. Nothing was written.';
$string['sectionnotmovable'] = 'Section "{$a->sectionnum}" does not exist or is the general section (0) - it cannot be moved.';

// Write core: set_completion (#392).
$string['completionunknownfield'] = 'Unknown completion field "{$a->field}". Allowed for this activity type: {$a->allowed_fields}. Nothing was written.';
$string['completionfieldviasetcompletion'] = 'The completion field "{$a->field}" is not set through a field patch but exclusively through set_completion (cmid, felder_json) - otherwise Moodle silently discards it or wipes the learners\' completion data. Nothing was written.';
$string['completionfieldnotformodname'] = 'The completion field "{$a->field}" only exists for the activity types {$a->modnames}, not for "{$a->modname}". Nothing was written.';
$string['completioninvalidfieldvalue'] = 'Invalid value "{$a->value}" for completion field "{$a->field}". Nothing was written.';
$string['completionnotenabled'] = 'Completion tracking is disabled for this course (or the whole site). Moodle would silently discard these fields either way. Enable completion tracking for the course first. Nothing was written.';
$string['completiondatalossconfirmationrequired'] = 'This change would delete the existing completion data of {$a->affected_learners} learner(s) for this activity - Moodle wipes and recalculates it as soon as this write unlocks completion. Nothing was written. Call set_completion again with "confirmed": true to proceed anyway.';
$string['learnerlocksunconfirmed'] = 'This call would set learner lock(s) for activity type "{$a->modname}" - settings after which a learner needs an action by the teacher to continue or resubmit: {$a->locks} Nothing was written. Leave the field out or choose an open value unless the teacher explicitly asked for this lock; only then repeat the call with "{$a->parameter}": {$a->ids}.';
$string['sectiontargetoutofrange'] = 'Target position "{$a->target}" is out of the valid range (1 to {$a->max}).';

// Write core: set_restriction (#393).
$string['restrictionsnotenabled'] = 'Conditional availability is disabled on this Moodle instance (setting "enableavailability"). Moodle would discard restrictions either way. Nothing was written.';
$string['invalidrestrictionjson'] = 'conditions_json is not a valid JSON array of condition objects. Nothing was written.';
$string['restrictionunknowntype'] = 'Invalid value {$a->value} for "{$a->field}". Allowed: "completion", "date", "group". Nothing was written.';
$string['restrictionactivitynotfound'] = 'Invalid value {$a->value} for "{$a->field}": no activity with this cmid in the same course. Nothing was written.';
$string['restrictioninvalidstatus'] = 'Invalid value {$a->value} for "{$a->field}". Allowed: complete, incomplete, pass, fail. Nothing was written.';
$string['restrictioninvaliddate'] = 'Invalid date condition ({$a->value}). "direction" must be "from" or "until", "timestamp" a Unix timestamp (integer). Nothing was written.';
$string['restrictiongroupnotfound'] = 'Invalid value {$a->value} for "{$a->field}": no group with this id in the same course. Nothing was written.';

// Write core: change history surface (#394).
$string['versionnotfound'] = 'Version {$a->version} does not exist for this activity (cmid {$a->cmid}).';

// Write core: quiz arrangement snapshot in change history (#396).
$string['arrangementrestoreblocked'] = 'The question arrangement of this quiz (quizid {$a->quizid}) cannot be restored: attempts already exist. From now on the recorded arrangement is history only, no longer restorable.';

// Spec 0017: quiz question attachment (#420).
$string['addquestionstoquizblocked'] = 'No questions can be added to this quiz (quizid {$a->quizid}) any more: attempts already exist. Nothing was changed.';

// Write core: drift check and admin status page (#399, ADR 0017).
$string['modnamedriftlocked'] = 'I cannot change activity type "{$a->modname}" right now - please report this to the administration. Other activity types remain writable, reading and lookups also remain possible.';
$string['driftcheckname'] = 'Coursepilot field catalog: {$a}';
$string['driftstatuschecked'] = 'Reviewed: field catalog manually reviewed for this Moodle major version, no drift.';
$string['driftstatusautochecked'] = 'Automatically reviewed: columns, callable sources and constants match, but this Moodle major version has not been manually reviewed yet (value lists, combination rules, side effects).';
$string['driftstatusbrauchtarbeit'] = 'Needs work: the field catalog no longer matches this Moodle instance, the activity type is locked for writing.';

// XML core: import_questions_xml (#415, Spec 0017 §7.1).
$string['roundtripmismatch'] = 'Round-trip check failed after writing: field "{$a->field}" differs from the imported XML{$a->detail}. Nothing was imported, the write was rolled back.';

// Spec 0017: clone_activity (#421).
$string['clonenobackupsupport'] = 'Activity type "{$a->modname}" does not support activity backup (no FEATURE_BACKUP_MOODLE2), so it cannot be cloned or exported.';
$string['createfromxmlcatalogued'] = 'Activity type "{$a->modname}" is catalogued: create it with coursepilot_create_module (see coursepilot_describe_module_fields), not from XML.';
$string['xmlroundtripmismatch'] = 'The activity XML did not survive creation: "{$a->path}" expected "{$a->expected}", Moodle stored "{$a->actual}" ({$a->count} deviation(s)). The activity was removed again, nothing is left in the course.';
$string['createfromxmlpresets'] = 'Moodle filled in fields that were not in your XML: {$a}.';
$string['createfromxmlreferences'] = 'These places still point at the superseded (now hidden) activity and were not changed: {$a}. Decide with the teacher whether to repoint them.';
$string['createfromxmlhiddenpredecessors'] = 'After superseding, the chain holds {$a} hidden earlier version(s) of this activity. Suggest to the teacher to clean up old versions that are no longer needed.';
$string['createfromxmlsuccessor'] = 'This activity was already superseded; the newest version is cmid {$a}. Ask the teacher whether that one should be superseded instead.';
$string['activityrestorefailed'] = 'Restoring the activity failed - Moodle did not report a new activity after the restore. Nothing usable was left behind.';
$string['activitybackupfailed'] = 'Exporting the activity failed - the backup did not contain an activity XML.';

// Administration: WebDAV status checks, Ablageort column, settings block (#499, Spec #486 §12).
$string['webdavcheckactionlink'] = 'Open now';
$string['webdavcheck1name'] = 'Coursepilot: WebDAV repository active';
$string['webdavcheck1ok'] = 'The "WebDAV" repository type is active.';
$string['webdavcheck1infooptional'] = 'The "WebDAV" repository type is not active. That is harmless as long as nobody has chosen an external storage location.';
$string['webdavcheck1warning'] = 'The "WebDAV" repository type is not active, but {$a} person(s) already point their context pointer at an external location. Those locations are currently unreachable.';
$string['webdavcheck2name'] = 'Coursepilot: User instances allowed';
$string['webdavcheck2na'] = 'Not applicable while the "WebDAV" repository type is inactive.';
$string['webdavcheck2ok'] = 'User instances of the "WebDAV" repository type are allowed.';
$string['webdavcheck2warning'] = 'User instances of the "WebDAV" repository type are not allowed — teachers cannot create their own connection. Recommendation when creating one: an app password instead of the actual account password.';
$string['webdavcheck3name'] = 'Coursepilot: WebDAV capability in the user context';
$string['webdavcheck3na'] = 'Not applicable: either the "WebDAV" repository type is inactive, or nobody currently has an active Coursepilot connection.';
$string['webdavcheck3ok'] = 'All persons with an active Coursepilot connection have the "repository/webdav:view" capability in their own user context.';
$string['webdavcheck3warning'] = '{$a->missing} of {$a->total} connected teachers are missing the "repository/webdav:view" capability in their own user context.';
$string['webdavcheck4name'] = 'Coursepilot: Approved storage for personal data';
$string['webdavcheck4info'] = 'No external storage approved — files marked as containing personal data stay in Moodle.';
$string['webdavcheck4ok'] = 'Approved external storage locations are configured.';

$string['connectionstoragelocation'] = 'Storage location';
$string['storagelocationtargetcontextarea'] = 'Context area';
$string['storagelocationtargetmaterialstore'] = 'Material store';
$string['storagelocationopen'] = 'open';
$string['storagelocationmoodle'] = 'in Moodle';
$string['storagelocationexternal'] = 'external: {$a}';
$string['storagelocationdefectinvalid'] = 'Pointer invalid';
$string['storagelocationdefectinstancemissing'] = 'instance missing';
$string['storagelocationdefectforeigninstance'] = 'belongs to someone else';
$string['storagelocationdefecthttp'] = 'http';
$string['storagelocationmarkernotallowed'] = 'storage not approved';
$string['storagelocationmarkerpending'] = 'pending write';
$string['storagelocationmarkerpreviouslocation'] = 'previous location still open';
$string['storagelocationmarkerdefect'] = 'broken pointer ({$a})';

$string['settingwebdavheading'] = 'External storage location (WebDAV)';
$string['settingwebdavheading_desc'] = 'What the school should know about the external storage location: names and images from the material store can go to the AI. A WebDAV user instance stores the password in plain text — an app password instead of the actual account password is recommended. Core gap: the repository provider for data access/deletion searches by "userid", but user instances carry "userid = 0" and are therefore not found. External context files have a write lock but no read lock. The current state of the four related status checks is on the <a href="{$a}">system status</a> page.';

$string['activitycleanupincomplete'] = 'Activity cleanup is incomplete. Unproven ownership prevents automatic deletion; inspect the failed creation in Moodle.';

// Stable OAuth connections (#638).
$string['privacy:metadata:oauth_grant'] = 'Stable user and client connections shared by token generations and download tickets.';
$string['privacy:metadata:oauth_grant:userid'] = 'The user who authorised the connection.';
$string['privacy:metadata:oauth_grant:clientid'] = 'The authorised AI client id.';
$string['privacy:metadata:oauth_grant:revoked'] = 'Whether the entire connection has been revoked.';
$string['privacy:metadata:oauth_grant:timecreated'] = 'Connection creation time.';
$string['privacy:metadata:oauth_token:connectionid'] = 'The stable connection owning this token generation.';
$string['privacy:metadata:workbench_ticket:oauthconnectionid'] = 'The stable connection that issued this download ticket.';

// Localized question and section tool messages (#605).
$string['questionbankreused'] = 'Question bank "{$a}" already existed, reusing it.';
$string['questionbankcreated'] = 'Question bank "{$a}" created.';
$string['questioncategoryreused'] = 'Category "{$a}" already existed, reusing it.';
$string['questioncategorycreated'] = 'Category "{$a}" created.';
$string['sectioncreatednamed'] = 'Section {$a->sectionnum} created, name set to "{$a->name}".';
$string['sectioncreated'] = 'Section {$a->sectionnum} created.';
$string['sectionreusedrenamed'] = 'Section {$a->sectionnum} already existed, name changed from "{$a->oldname}" to "{$a->name}".';
$string['sectionreused'] = 'Section {$a->sectionnum} already existed, name unchanged.';
$string['mcquestionsuspect'] = 'Suspect case: The target category already contains an entry named "{$a}". Nothing was created. To create a new entry anyway, call again with confirmed=true.';
$string['mcquestioncreated'] = 'MC question "{$a->name}" created (bank entry {$a->entryid}, version {$a->version}).';
$string['questionexportpath'] = 'File: {$a}.';
$string['questionexportone'] = '1 question exported.';
$string['questionexportmany'] = '{$a} questions exported.';
$string['questionexportplaceholder'] = 'PLACEHOLDER MODE: This output is incomplete (embedded files have been replaced by comment placeholders) and NOT suitable for sharing - only for templates (learning question structure). For complete, shareable XML, use placeholder=false (default).';
$string['questionexportmissingdetail'] = 'Question "{$a->name}": {$a->files}';
$string['questionexportmissing'] = 'WARNING: Embedded files are missing from the export and have been replaced by placeholders ({$a}).';

$string['questionimportsuspect'] = 'Suspect case: The supplied idnumber "{$a}" has no match in the target category. Nothing was imported. To create a new entry anyway, call again with confirmed=true.';
$string['questionimportcreated'] = 'Question "{$a->name}" created (version {$a->version}).';
$string['questionimportversion'] = 'Question "{$a->name}" imported as a new version (version {$a->version}) of the same bank entry.';

$string['versioninfosummary'] = 'Moodle {$a->moodlerelease} (branch {$a->branch}), Coursepilot plugin {$a->pluginrelease} (version {$a->pluginversion}).';
$string['versioninfoupgradewarning'] = 'Warning: The database records version {$a} - upgrade.php was not run after the last deployment.';
$string['quizcreatedfields'] = 'Quiz created: {$a}.';

// Teacher-authored glossary entries (#593).
$string['tool_add_glossary_entries'] = 'Adds teacher-authored entries to a fresh or existing glossary by cmid. Entries support concept, definition/definitionformat, aliases, category names, automatic linking, case sensitivity, whole-word matching, approval, tags, attachment_files and definition_files from store/workbench. Missing categories require mod/glossary:managecategories; explicit approval requires mod/glossary:approve. Requires mod/glossary:write and local/coursepilot:use. Each entry returns success or a safe error reason independently; forbidden duplicates fail only that entry. Existing learner entries, comments and ratings are never returned. Glossary child content is not covered by activity version history.';
$string['glossaryentrybusy'] = 'The glossary is being updated. Please retry.';
$string['glossaryentryrequired'] = 'Concept and definition must not be empty.';
$string['glossaryentrycategoryrequired'] = 'Category names must not be empty.';
$string['glossaryentryfailed'] = 'The glossary entry could not be saved.';
$string['glossaryentryfilelimit'] = 'Entry files exceed Moodle\'s file count or upload size limit.';
$string['glossaryentryformat'] = 'Definition format must be Moodle, HTML, plain text or Markdown.';
$string['glossaryentrytagsdisabled'] = 'Tags are disabled for glossary entries.';
$string['glossaryentrystandardtags'] = 'This glossary allows only existing standard tags.';

// Verified activity-type knowledge supplied at location selection (#603).
$string['activitytypetemplatesprovided'] = 'Activity-type templates supplied: {$a}.';
