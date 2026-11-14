# Prune-VORSCHLAG aus der Kill-Matrix (#753, 2026-11-14)

**Nur ein Vorschlag. Kein Test wurde gelöscht oder geändert.** Löschung braucht eine neue HITL-Freigabe von Matthias für genau eine Liste daraus.

## Entscheidung vom 14.11.2026 (Matthias)

- **Nachlauf abgelehnt, keine weiteren Mutationsläufe für #753.** Gründe: ca. 5 h Rechenzeit plus Instanz, um die Löschung von 84 Tests mit zusammen 12 s Laufzeit abzusichern, ist kein messbarer Nutzen. Der Nachlauf könnte außerdem die Hauptgruppe von Tier 1 kaum beurteilen: Tests, die ausgeschlossene Dateien schützen (`db/services.php`, `lang/`), töten nie einen Mutanten.
- **Tier 1 und Tier 2 bleiben ohne Freigabe.** Es gibt keine Löschfreigabe, auch nicht für Tier 1. Dieser Bericht ist **Messstand**, keine Löschliste. Die neun Katalog-Vertragstests in Tier 1B fallen unter T4.
- **Sichtung der 84 Tier-1-Tests** (reine Textsuche im Testrumpf auf Muster, kein Lauf, kein Beweis): Die Annahme „der Test schützt eine von der Mutation ausgeschlossene Datei“ trägt **nur zu einem Teil**.
  - 29 von 84 verweisen direkt auf ausgeschlossene Bereiche (`lang/`/`get_string` 14, Capabilities/`db/access.php` 13, Events/Tasks 3, Einstiegsskripte 4; Mehrfachtreffer möglich). `db/services.php` direkt: 0, `version.php`: 0.
  - 29 weitere haben eine andere Erklärung, warum keine Mutation sie treffen kann: Quelltext-/Dateiinhaltsprüfung 21, optionales Plugin Lightbox (laut Spec 0029 Umgebungsgrenze) 10, Registrierungs-/Katalogprüfung 15 (Mehrfachtreffer möglich).
  - 26 von 84 haben im Rumpf keinen dieser Hinweise (u. a. Katalog-Vertragstests, Konstantenprüfungen, Profil-/Navigations-Tests von `lib.php`, Quiz-/Modul-Einstellungen). Für sie ist die Annahme **nicht belegt**; ein fehlender Kill ist dort kein Beweis für Wertlosigkeit.
  - Alle 74 Tier-1A-Tests liefen auf keinem Mutanten (kein Line-Coverage-Treffer); 10 Tier-1B liefen und töteten keinen.

Quelle: [Kill-Matrix](753-kill-matrix-2026-11-14.md), Rohbelege dort. Stand: Quellstand 38b16690b4f767f0e29cef15c42f7222abf78b94, Plugin-Baumdigest 71914b36927066e3d68866ad6b8aca1bcf467a40302b6e9de35a5ace3910719e, Coverage-JUnit sha256 34952223e1baf67c (2805 Testmethoden), Infection 0.29.14, 27 Abschnitte. Nicht zugeordnete Wrapper-Artefakte: 0. UNVOLLSTÄNDIG: 20 getötete/Timeout-Mutanten ohne Failing-Daten.

## Methode

- Schutzmenge je Mutant = alle Tests, die ihn töten (Assertion-Fehler, Fehler; bei reinem Warnungs-Kill die auslösenden Tests; bei fehlender JUnit alle gelaufenen Tests).
- Tier 1: Test taucht in **keiner** Schutzmenge auf. Tier 2: jeder Mutant, den der Test tötet, wird auch von einem Test getötet, der bleibt (Greedy, aufsteigend nach Zahl der Kills; Gegenprobe gegen die Endliste: 0 Verletzungen).
- Ausgenommen: Vertragstests (CODING_STANDARDS T4, Story 33), Erkennung per Klassenname **und** Testrumpf (nicht nur Methodenname): 96. Subprozess-Verdacht (Rumpf nutzt Kindprozess, pcov sieht ihn nicht): 51, bleiben stehen.
- Grenze: `--only-covering-test-cases` lässt Tests ohne Line-Coverage-Treffer nicht laufen. Ein Test, der nur außerhalb davon töten würde (Überlebende, ungedeckte Mutanten), ist in der Matrix unsichtbar → Nachlauf siehe unten. Tests, die Verhalten ohne mutierbare Zeile schützen (Integration, Kompatibilität), tötet kein Mutant: fehlender Kill ist kein Beweis für Wertlosigkeit.

## Zahlen

| | Anzahl | Laufzeit s (Coverage-JUnit) |
|---|---:|---:|
| Testmethoden gesamt | 2805 | |
| Vertragstests (ausgenommen) | 96 | |
| Subprozess-Verdacht (bleiben) | 51 | |
| Tier 1A: lief auf keinem Mutanten (kein Coverage-Treffer) | 74 | 10 |
| Tier 1B: lief auf Mutanten, tötete keinen | 10 | 2 |
| Tier 2: Kills vollständig durch bleibende Tests geschützt | 1393 | 571 |
| bleiben (einzige Töter mindestens eines Mutanten) | 1181 | |

Unvollständige Matrixdaten: 20 Mutanten (Timeout/Fehler/ohne JUnit); sie schützen konservativ alle auf ihnen gelaufenen Tests. Gesamtzustand: UNVOLLSTÄNDIG, siehe Kill-Matrix.

## Nutzen und Risiko

- Nutzen: weniger Wartung und Laufzeit (Spalte oben, nur Coverage-Lauf-Zeit; Mutationslauf-Zeit nicht neu gemessen).
- Risiko Tier 1A: höchstes (Coverage-Artefakt möglich: Subprozess, pcov-Lücke). Nur nach Nachlauf (unten) und Einzelprüfung vorschlagbar.
- Risiko Tier 1B: mittel; Test deckt Zeilen, aber keine Mutation macht ihn rot, er kann Verhalten sichern, das keine Mutation trifft.
- Risiko Tier 2: niedrig bis mittel; Mutations-Redundanz, aber nicht Intention (zwei Tests, derselbe Fehler).

## Tier 1A (lief auf keinem Mutanten)

- activity_file_supplement_test::test_applying_files_commits_and_leaves_no_draft_behind
- activity_file_supplement_test::test_files_need_the_right_to_manage_own_files_before_anything_is_restored
- activity_file_supplement_test::test_hidden_workbench_image_and_empty_file_list_keep_existing_creation_options
- activity_file_supplement_test::test_invalid_area_duplicate_filename_and_non_image_leave_no_activity
- activity_file_supplement_test::test_missing_later_path_discards_only_the_new_activity_and_keeps_course_unchanged
- activity_file_supplement_test::test_native_image_permission_is_required_and_failure_keeps_the_course
- activity_file_supplement_test::test_plugin_files_without_an_installed_module_do_not_open_the_kind_gate
- activity_file_supplement_test::test_rejected_file_input_is_refused_before_any_restore_happens
- activity_file_supplement_test::test_three_material_images_and_captions_are_displayed_with_native_thumbnails
- append_context_file_test::test_german_messages_carry_the_required_wording
- connection_access_test::test_unused_token_state_methods_are_removed
- context_files_test::test_legacy_anchor_still_addressable
- context_files_test::test_storage_anchor_is_private_files
- create_activity_from_xml_test::test_is_registered_as_write_tool
- create_activity_from_xml_test::test_lightboxgallery_missing_timemodified_is_rejected_without_mutation
- create_module_test::test_source_never_writes_the_instance_table_directly
- create_quiz_test::test_source_never_writes_the_quiz_table_directly
- describe_module_fields_test::test_introduces_no_write_capability
- drift_check_test::test_every_catalog_declares_reviewed_up_to_major
- export_activity_backup_test::test_is_registered_as_read_tool
- field_test::test_all_activity_field_validators_delegate_to_the_catalog
- field_test::test_invalid_field_name_uses_the_english_language_string
- instance_check_test::test_requirements_name_the_three_prerequisites
- lib_test::test_adds_no_node_without_viewhistory_capability
- lib_test::test_adds_node_with_viewhistory_capability
- lib_test::test_profile_links_appear_only_in_the_own_profile
- lib_test::test_settings_navigation_adds_coursepilot_block_for_own_page
- lib_test::test_settings_navigation_adds_nothing_for_foreign_profile
- lib_test::test_settings_navigation_adds_nothing_without_capability
- lib_test::test_status_checks_include_the_four_webdav_checks
- lib_test::test_status_checks_return_one_check_per_catalogued_activity_type
- location_selection_test::test_external_location_privacy_info_names_all_four_facts
- location_selection_test::test_state_and_timeout_constants_have_the_expected_values
- material_files_test::test_root_defaults_to_coursepilot_material
- material_files_test::test_storage_anchor_is_private_files
- no_deprecated_move_functions_test::test_plugin_source_never_calls_forbidden_functions_directly
- oauth_cleanup_test::test_scheduled_tasks_are_named_for_the_admin_overview
- personal_data_hosts_check_test::test_identifies_itself_for_the_status_report
- personal_data_hosts_check_test::test_is_info_when_list_is_empty
- personal_data_hosts_check_test::test_is_ok_when_hosts_are_configured
- pointer_scan_test::test_state_and_defect_constants_have_the_expected_values
- quiz_catalog_contract_test::test_catalog_notes_that_ordering_is_out_of_scope
- quiz_catalog_contract_test::test_schreibweg_is_update_quiz_settings
- read_context_file_test::test_german_read_failure_message_names_the_context_gap
- remote_access_test::test_teacher_archetypes_do_not_default_to_remote_access
- restore_activity_version_quiz_test::test_arrangement_is_not_registered_as_mcp_tool
- retention_test::test_install_registers_task_and_indexes
- retention_test::test_scheduled_purge_is_named_for_the_admin_overview
- set_restriction_test::test_profile_condition_from_elsewhere_stays_masked_on_read
- tool_schema_contract_test::test_every_catalog_description_is_english
- umlaut_test::test_kontextbereich_ausstand_forbids_taking_another_location
- umlaut_test::test_kontextbereich_does_not_fix_handaenderung_to_meine_dateien
- umlaut_test::test_kontextbereich_replaces_verbindungsaufbau_sentence
- umlaut_test::test_merkzettel_separates_previouslocation_from_materialbestand_wechsel
- umlaut_test::test_no_ascii_umlaut_substitutes_in_chain_texts
- umlaut_test::test_plugin_description_states_write_lock_not_read_lock
- update_module_settings_test::test_deprecated_set_coursemodule_groupmode_is_never_used
- update_module_settings_test::test_qanda_showimmediately_can_be_changed
- update_module_settings_test::test_source_never_writes_the_instance_table_directly
- update_module_settings_test::test_title_patch_preserves_multi_marker_configuration
- update_quiz_settings_test::test_due_date_can_be_changed_without_resetting_other_settings
- update_quiz_settings_test::test_due_date_order_is_validated_without_writes
- update_quiz_settings_test::test_source_never_touches_quiz_slots
- update_quiz_settings_test::test_source_never_writes_the_quiz_table_directly
- webdav_capability_check_test::test_check_links_to_the_relevant_admin_page
- webdav_capability_check_test::test_identifies_itself_for_the_status_report
- webdav_repository_check_test::test_check_links_to_the_relevant_admin_page
- webdav_repository_check_test::test_identifies_itself_for_the_status_report
- webdav_storage_port_test::test_write_rejects_when_quota_is_exceeded
- webdav_user_instances_check_test::test_check_links_to_the_relevant_admin_page
- webdav_user_instances_check_test::test_identifies_itself_for_the_status_report
- xml_activity_creator_674_final_contract_test::test_create_attaches_declared_files_to_a_lightboxgallery
- xml_activity_creator_674_final_contract_test::test_lightboxgallery_xml_with_timemodified_passes_the_xml_check
- xml_activity_creator_contract_test::test_a_lightboxgallery_xml_without_timemodified_is_refused_with_the_export_hint

## Tier 1B (lief, tötete keinen)

- assign_catalog_contract_test::test_introattachments_is_catalogued_and_unlocked (lief auf 211 Mutanten)
- assign_catalog_contract_test::test_required_constants_still_exist (lief auf 1 Mutanten)
- catalog_state_test::test_name_only_catalogs (lief auf 2 Mutanten)
- choice_catalog_contract_test::test_no_coursepilot_option_upper_bound (lief auf 178 Mutanten)
- page_catalog_contract_test::test_display_is_a_real_field_not_a_pseudofield (lief auf 77 Mutanten)
- page_catalog_contract_test::test_printheading_is_absent (lief auf 77 Mutanten)
- quiz_catalog_contract_test::test_every_field_carries_a_german_meaning_and_source (lief auf 283 Mutanten)
- url_catalog_contract_test::test_externalurl_is_not_param_url (lief auf 25 Mutanten)
- url_catalog_contract_test::test_url_has_no_revision_column (lief auf 1 Mutanten)
- webdav_capability_check_test::test_is_na_without_any_connection (lief auf 4 Mutanten)

## Tier 2 (nicht empfohlen)

1393 Tests töten nur Mutanten, die auch ein bleibender Test tötet. Das ist Mutations-Redundanz, keine Test-Redundanz (zwei Tests dürfen denselben Fehler aus verschiedenen Gründen prüfen, ein Mutant sieht nur einen Teil davon). Die vollständige Liste mit Schutzbeweis je Test steht in `docs/checks/evidence/753/prune-proposal-2026-11-14.json`. **Keine Empfehlung zur Löschung**, die Menge wäre rund die Hälfte des Bestands. Auszug (erste 15):

- access_log_test::test_default_level_is_reads_and_errors: 1 Kills, mind. 2 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/access_log.php:55 PublicVisibility → access_log_test::test_an_empty_stored_level_falls_back_to_reads_and_errors, access_log_test::test_unset_or_empty_level_falls_back_to_reads_and_stored_level_is_int
- activity_drift_test::test_id_is_unique_per_modname: 1 Kills, mind. 1 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/check/activity_drift.php:56 ConcatOperandRemoval → activity_drift_test::test_identifies_itself_per_module_type
- append_context_file_test::test_rejects_traversal: 1 Kills, mind. 36 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/append_context_file.php:93 PublicVisibility → admin_pointer_tools_contract_test::test_appending_checks_the_context_first_and_records_a_failed_external_write_without_a_course, append_context_file_test::test_accepts_append_to_marked_file_at_allowed_external_host, append_context_file_test::test_append_rejects_change_after_preflight
- assign_catalog_contract_test::test_standard_and_uebung_bundles_are_shipped: 1 Kills, mind. 2 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/catalog/assign.php:911 ArrayItemRemoval → catalog_definition_snapshot_test::test_catalog_definition_matches_the_fixture, describe_module_fields_test::test_assign_short_form_uses_common_fields_subset
- choice_catalog_contract_test::test_limit_length_rule_is_a_combination_rule: 1 Kills, mind. 1 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/catalog/choice.php:361 ArrayItemRemoval → catalog_definition_snapshot_test::test_catalog_definition_matches_the_fixture
- connection_lifecycle_test::test_rotate_refresh_token_rejects_unknown_token: 1 Kills, mind. 19 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/connection.php:144 PublicVisibility → connection_674_final_contract_test::test_a_successful_rotation_closes_its_transaction, connection_access_test::test_access_requires_an_active_owned_connection_account_and_remote_grant, connection_contract_test::test_refreshing_with_a_replayed_token_revokes_the_connection_and_leaves_no_open_transaction
- delete_material_files_test::test_rejects_traversal_path: 1 Kills, mind. 7 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/delete_material_files.php:59 PublicVisibility → delete_material_files_test::test_deletes_exactly_named_files, delete_material_files_test::test_empty_list_deletes_nothing, delete_material_files_test::test_missing_path_aborts_without_partial_delete
- ensure_quiz_question_categories_test::test_rejects_unenrolled_user: 1 Kills, mind. 9 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/ensure_quiz_question_categories.php:60 PublicVisibility → context_validation_contract_test::test_a_visible_quiz_becomes_the_page_context, context_validation_contract_test::test_the_quiz_categories_are_refused_for_a_quiz_hidden_from_the_user, ensure_quiz_question_categories_test::test_question_can_be_transferred_and_moved_without_losing_quiz_reference
- export_questions_xml_test::test_requires_at_least_one_questionid: 1 Kills, mind. 15 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/export_questions_xml.php:77 PublicVisibility → export_questions_xml_contract_test::test_a_quota_warning_follows_the_path_in_the_message, export_questions_xml_contract_test::test_an_empty_list_and_a_missing_target_path_are_refused_with_their_reason, export_questions_xml_contract_test::test_embedded_files_are_replaced_and_named_in_the_placeholder_message
- folder_catalog_contract_test::test_folder_display_constants_exist: 1 Kills, mind. 1 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/catalog/folder.php:254 ArrayItemRemoval → catalog_definition_snapshot_test::test_catalog_definition_matches_the_fixture
- forum_catalog_contract_test::test_forum_initialsubscribe_constant_exists: 1 Kills, mind. 1 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/catalog/forum.php:466 ArrayItemRemoval → catalog_definition_snapshot_test::test_catalog_definition_matches_the_fixture
- forum_catalog_contract_test::test_side_effects_note_calendar_entries: 1 Kills, mind. 1 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/catalog/forum.php:437 ConcatOperandRemoval → catalog_definition_snapshot_test::test_catalog_definition_matches_the_fixture
- get_modules_test::test_rejects_user_without_enrolment: 1 Kills, mind. 12 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/get_modules.php:60 PublicVisibility → get_modules_test::test_a_section_number_selects_the_section_of_the_requested_course_only, get_modules_test::test_lists_activities_in_section_and_sequence_order, get_modules_test::test_rejects_user_without_capability
- get_question_categories_test::test_rejects_user_without_enrolment: 1 Kills, mind. 3 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/get_question_categories.php:64 PublicVisibility → get_question_categories_test::test_rejects_user_without_capability, get_question_categories_test::test_returns_top_category_and_subcategories, small_tools_contract_test::test_the_category_list_has_typed_rows_of_this_bank_only_and_creates_the_missing_top_category
- get_question_test::test_rejects_user_without_enrolment: 1 Kills, mind. 12 bleibende Töter je Mutant, z. B. Plugin/src/local_coursepilot/classes/external/get_question.php:73 PublicVisibility → get_question_673_contract_test::test_an_entry_without_a_readable_version_is_reported_with_its_entry_id, get_question_contract_test::test_a_lookup_by_id_ignores_the_name_and_other_questions, get_question_contract_test::test_each_capability_is_checked_before_the_lookup

## Empfehlung und offener Nachlauf

- Nur Tier 1 (84 Tests, 12 s) kommt für eine Freigabe in Frage, Tier 2 nicht.
- **Nachlauf noch nicht gelaufen:** Die Tier-1-Tests wurden nicht gegen die 831 Überlebenden und 58 ungedeckten Mutanten gelaufen (dort läuft wegen `--only-covering-test-cases` kein Test außerhalb der Line-Coverage). Bis dahin ist Tier 1A (kein Coverage-Treffer) nicht freigabereif, Tier 1B nur nach Einzelprüfung.
- Einzelprüfung: 9 der 10 Tier-1B-Tests heißen nach Katalog-/Vertragstests. Der Name entscheidet nichts, aber sie sind vor einer Freigabe auf Vertragscharakter (T4) zu lesen.
- Nutzen insgesamt klein (Tier 1: 12 s von ca. 1.300 s Suite); Hauptnutzen ist weniger Wartung, nicht Laufzeit.

## Vertragstests (ausgenommen)

- add_glossary_entries_test::test_dispatcher_exposes_and_invokes_registered_tool_as_teacher_without_learner_content: asserts the privacy surface
- add_questions_to_quiz_test::test_registered_in_service_and_allowlist: asserts the privacy surface
- add_questions_to_quiz_test::test_rejects_user_without_capability: asserts the published tool allowlist
- append_context_file_test::test_external_append_ignores_moodle_quota_and_capability: asserts the published tool allowlist
- append_context_file_test::test_registered_in_service_and_allowlist: asserts the privacy surface
- assign_catalog_contract_test::test_teamsubmissiongroupingid_notes_same_course_restriction: asserts the published tool allowlist
- compose_material_file_test::test_tool_is_registered_as_writing: asserts the privacy surface
- context_files_test::test_tool_names_reveal_no_storage_location: asserts the privacy surface
- dismiss_pending_entry_test::test_person_a_cannot_dismiss_person_bs_entry: asserts the published tool allowlist
- dismiss_pending_entry_test::test_registered_in_service_and_allowlist: asserts the privacy surface
- dismiss_previous_location_test::test_person_a_cannot_dismiss_person_bs_previouslocation: asserts the published tool allowlist
- dismiss_previous_location_test::test_registered_in_service_and_allowlist: asserts the privacy surface
- dispatcher_responses_test::test_tool_list_describes_every_allowed_tool: asserts the privacy surface
- dispatcher_test::test_history_comparison_protects_current_and_legacy_data: asserts the published tool allowlist
- dispatcher_test::test_list_skills_tool_description_carries_the_same_hint: asserts the published tool allowlist
- dispatcher_test::test_moodle_webservice_token_is_no_longer_accepted: asserts the privacy surface
- dispatcher_test::test_tools_list_is_derived_from_allowlist: asserts the privacy surface
- get_course_catalog_test::test_group_names_never_appear_only_groupmode: asserts the registered service / capability definition
- get_question_category_cleanup_plan_test::test_lists_empty_leaf_without_deleting_it: asserts the published tool allowlist
- get_question_category_cleanup_plan_test::test_registered_as_read_only_tool: asserts the privacy surface
- history_provider_test::test_context_deletion_removes_whole_history_there_and_keeps_shared_metadata: Privacy API contract (provider and metadata)
- history_provider_test::test_delete_for_user_removes_own_states_in_approved_context_only: Privacy API contract (provider and metadata)
- history_provider_test::test_delete_for_users_removes_only_approved_users: Privacy API contract (provider and metadata)
- history_provider_test::test_discovery_finds_authors_in_real_module_contexts: Privacy API contract (provider and metadata)
- history_provider_test::test_export_contains_only_own_states_and_allowed_file_metadata: Privacy API contract (provider and metadata)
- history_provider_test::test_export_describes_each_own_state_with_its_exact_metadata: Privacy API contract (provider and metadata)
- history_provider_test::test_export_of_a_context_without_own_states_writes_nothing: Privacy API contract (provider and metadata)
- history_provider_test::test_metadata_declares_snapshot_fields_and_configurable_retention: Privacy API contract (provider and metadata)
- history_provider_test::test_private_files_are_left_to_core: Privacy API contract (provider and metadata)
- history_provider_test::test_unapproved_contexts_keep_history: Privacy API contract (provider and metadata)
- install_test::test_capabilities_are_registered: Privacy surface / install / upgrade smoke contract
- install_test::test_external_service_is_registered: Privacy surface / install / upgrade smoke contract
- install_test::test_plugin_is_installed_and_requires_moodle_50: Privacy surface / install / upgrade smoke contract
- oauth_cleanup_test::test_cleanup_indexes_are_installed_and_added_idempotently_on_upgrade: asserts the registered service / capability definition
- oauth_connection_upgrade_test::test_interrupted_backfill_resumes_without_guessing_historical_families: asserts the registered service / capability definition
- pages_contract_test::test_the_surface_data_lists_everything_in_its_order_and_hides_extra_violation_fields: asserts the privacy surface
- privacy_surface_674_final_contract_test::test_duplicate_forbidden_name_is_reported_once: Privacy surface / install / upgrade smoke contract
- privacy_surface_674_final_contract_test::test_forbidden_component_violation_has_exact_detail: Privacy surface / install / upgrade smoke contract
- privacy_surface_674_final_contract_test::test_mixed_case_name_reports_the_lowercase_component: Privacy surface / install / upgrade smoke contract
- privacy_surface_674_final_contract_test::test_name_with_two_components_is_reported_per_component: Privacy surface / install / upgrade smoke contract
- privacy_surface_674_final_contract_test::test_registered_functions_are_listed_sorted: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_forbidden_tokens_are_rejected: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_function_added_to_service_by_admin_is_detected: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_missing_registration_is_detected: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_missing_service_registers_no_functions: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_own_surface_carries_no_forbidden_token: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_registered_surface_matches_contract: Privacy surface / install / upgrade smoke contract
- privacy_surface_test::test_tool_allowlist_resolves_approved_names_only: Privacy surface / install / upgrade smoke contract
- private_files_storage_port_test::test_nested_write_preserves_the_complete_client_path: asserts the published tool allowlist
- provider_674_final_contract_test::test_module_context_deletion_keeps_context_marks: Privacy API contract (provider and metadata)
- provider_674_final_contract_test::test_user_context_deletion_removes_only_that_users_marks: Privacy API contract (provider and metadata)
- provider_stores_test::test_contexts_for_user_include_system_for_each_store_alone: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_all_in_course_context_touches_nothing: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_all_in_system_context_empties_each_store: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_for_user_in_a_module_context_removes_only_own_states: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_for_user_removes_each_store_of_that_user_only: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_for_user_spares_foreign_contexts_and_foreign_user_contexts: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_for_user_without_system_context_keeps_the_stores: Privacy API contract (provider and metadata)
- provider_stores_test::test_delete_for_users_removes_each_store_of_the_listed_users_only: Privacy API contract (provider and metadata)
- provider_stores_test::test_deleting_user_data_preserves_history_with_a_matching_numeric_module_id: Privacy API contract (provider and metadata)
- provider_stores_test::test_export_covers_all_approved_contexts_of_the_user: Privacy API contract (provider and metadata)
- provider_stores_test::test_export_writes_marks_only_into_the_users_own_context: Privacy API contract (provider and metadata)
- provider_stores_test::test_metadata_declares_every_table_with_its_fields: Privacy API contract (provider and metadata)
- provider_stores_test::test_system_export_contains_each_store_with_its_fields: Privacy API contract (provider and metadata)
- provider_stores_test::test_user_with_only_directory_entries_is_not_listed: Privacy API contract (provider and metadata)
- provider_stores_test::test_users_in_system_context_cover_each_store: Privacy API contract (provider and metadata)
- provider_stores_test::test_users_in_user_and_foreign_context_do_not_leak: Privacy API contract (provider and metadata)
- provider_test::test_access_log_events_carry_attribution_for_core_logstore_handling: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_all_users_ignores_foreign_contexts: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_all_users_in_context_removes_context_files: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_all_users_in_system_context_removes_oauth_stores: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_user_removes_all_bestaende_and_spares_others: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_user_removes_mark_memory_and_spares_others: Privacy API contract (provider and metadata)
- provider_test::test_delete_data_for_users_removes_context_files: Privacy API contract (provider and metadata)
- provider_test::test_export_contains_context_files: Privacy API contract (provider and metadata)
- provider_test::test_export_contains_mark_memory_entries: Privacy API contract (provider and metadata)
- provider_test::test_export_contains_oauth_connections_and_tokens: Privacy API contract (provider and metadata)
- provider_test::test_get_contexts_for_userid_finds_mark_memory_only_context: Privacy API contract (provider and metadata)
- provider_test::test_get_users_in_context_finds_mark_memory_only_user: Privacy API contract (provider and metadata)
- provider_test::test_get_users_in_context_lists_oauth_users_in_system_context: Privacy API contract (provider and metadata)
- provider_test::test_get_users_in_context_requires_actual_files: Privacy API contract (provider and metadata)
- provider_test::test_metadata_declares_the_external_webdav_location: Privacy API contract (provider and metadata)
- read_context_file_test::test_context_write_surface_is_exactly_two_tools: asserts the privacy surface
- surface_page_test::test_violations_are_mapped_into_rows: asserts the published tool allowlist
- tool_schema_contract_test::test_all_public_tool_surfaces_are_derived_from_the_registration: asserts the privacy surface
- upgrade_test::test_late_upgrade_keeps_existing_connections_and_recreates_the_budget_table_once: Privacy surface / install / upgrade smoke contract
- upgrade_test::test_upgrade_from_pre_oauth_install_matches_the_current_install_schema: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_anchor_files_are_renamed_and_translated: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_hash_upgrade_preserves_existing_connection_without_retaining_cleartext: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_history_sources_are_migrated_to_english: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_repair_drops_token_rows_without_refresh_token: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_repair_is_idempotent: Privacy surface / install / upgrade smoke contract
- upgradelib_test::test_repair_removes_oauth_schema_drift: Privacy surface / install / upgrade smoke contract
- webdav_storage_port_test::test_nested_write_preserves_the_complete_client_path: asserts the published tool allowlist
- write_context_file_test::test_german_messages_carry_the_required_wording: asserts the published tool allowlist
- write_context_file_test::test_registered_in_service_and_allowlist: asserts the privacy surface

## Subprozess-Verdacht (bleiben)

- activity_backup_test::test_export_returns_activity_xml_and_leaves_no_tempdir: Muster `\bproc_open\s*\(`
- activity_backup_test::test_failed_restore_preserves_concurrent_native_creation_and_recyclebin: Muster `\bproc_open\s*\(`
- activity_backup_test::test_failed_restore_removes_half_made_activity_and_tempdir: Muster `\bproc_open\s*\(`
- activity_backup_test::test_missing_restore_identity_reports_incomplete_cleanup_without_deletion: Muster `\bproc_open\s*\(`
- activity_backup_test::test_partial_restore_removes_its_task_owned_instance_before_cm_linking: Muster `\bproc_open\s*\(`
- activity_backup_test::test_restore_from_activity_xml_creates_activity_in_section: Muster `\bproc_open\s*\(`
- activity_backup_test::test_restore_from_real_backup_clones_activity: Muster `\bproc_open\s*\(`
- activity_backup_test::test_restore_rejects_xml_that_is_not_an_activity: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_delivered_example_creates_and_roundtrips: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_external_concurrent_creation_is_preserved_after_conditional_put_conflict: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_external_preread_failure_does_not_interrupt_selection_or_other_templates: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_external_write_outage_retains_new_selection_and_pending_notes: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_location_selection_preserves_teacher_edits_and_locked_files: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_location_selection_supplies_verified_templates_in_private_files: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_missing_private_files_permission_does_not_interrupt_selection: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_private_files_concurrent_creation_is_never_replaced: Muster `\bproc_open\s*\(`
- activity_type_templates_test::test_repeated_location_selection_has_no_writes_or_template_notice: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_cleanup_failure_returns_an_explicit_error_instead_of_xml: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_hidden_export_preserves_concurrent_native_creation_and_recyclebin: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_is_registered_as_write_tool: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_leaves_nothing_when_recyclebin_enabled: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_native_creation_and_deletion_events_remain_observable: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_rejects_catalogued_kind_with_create_module_hint: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_rejects_excluded_kind_with_reason: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_requires_backup_capability: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_requires_edit_capability: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_requires_the_native_add_right_of_the_activity_type: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_returns_default_xml_and_leaves_nothing: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_the_temporary_activity_is_created_hidden_on_the_course_page_too: Muster `\bproc_open\s*\(`
- export_default_activity_test::test_works_for_glossary: Muster `\bproc_open\s*\(`
- history_cleanup_race_test::test_cleanup_does_not_remove_metadata_being_reused_by_capture: Muster `\bproc_open\s*\(`
- history_cleanup_race_test::test_failed_capture_leaves_no_partial_state_or_open_transaction: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_duplicate_cimd_insert_returns_stored_client: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_failed_fetches_are_negatively_cached: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_fetch_policy_and_deadline_are_preserved: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_invalid_ids_consume_no_budget: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_oversized_streaming_metadata_is_not_cached: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_parallel_unknown_ids_cannot_exceed_budget: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_public_fetch_sends_no_storage_credentials: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_tls_identity_is_required: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_transport_failures_use_budget_and_are_negatively_cached: Muster `\bproc_open\s*\(`
- oauth_cimd_fetch_test::test_unknown_ids_are_budgeted_before_first_fetch: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_confidential_client_must_present_its_secret: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_parallel_code_exchange_issues_only_one_token_pair: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_parallel_connection_changes: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_refresh_grant_without_refresh_token_is_an_invalid_request: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_replay_revocation_rolls_back_as_one_connection_change: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_rotation_preserves_connection_and_consumed_refresh_hash: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_stolen_refresh_replay_revokes_every_successor_and_ticket: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_tickets_survive_rotation_and_end_with_their_connection: Muster `\bproc_open\s*\(`
- oauth_connection_test::test_token_endpoint_does_not_disclose_replay_or_secrets: Muster `\bproc_open\s*\(`
