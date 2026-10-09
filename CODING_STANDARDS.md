# Coding Standards

> **Proposal (#656), maintainer decisions recorded.** Adoption by the association developers
> is pending; until then this file is the basis for that vote. Sources and their checks:
> `docs/research/coding-standards-quellen.md`.

This file holds only the rules **no tool enforces**. The gate (ADR 0029, ADR 0030) enforces
coverage, CRAP, mutation, moodle-cs, phpdoc, PHPStan and the layer dependencies; when the
gate is red, fix the code until it is green. A rule that a tool can check belongs in the
tool, and leaves this file the day the tool checks it.

Each rule reads: **rule** → why → source. A reviewer cites the rule by its number (`C3`,
`M4`, `P5`).

---

## Part 1 — Core (any project, any stack)

Written stack-neutral so other association projects can adopt this part unchanged.

**C1. Tooling owns every checkable rule.** When a rule can be checked mechanically, add the
check to the gate and delete the prose rule.
Why: agents treat prose as guidance and checks as law; two homes for one rule drift apart.
Source: ADR 0029 (Context); Spec 0029, user stories 40–41.

**C2. Name things with the domain's words.** Identifiers, test titles and messages use the
term the project glossary defines for that concept, one term per concept. New or changed
terms go into `CONTEXT.md` first (via the `/domain-modeling` skill or equivalent); when a
term changes, the classes, methods and keys that carry it are renamed with it.
Why: a shared language lets readers, reviewers and agents find a concept by its name.
Source: Evans, *Domain-Driven Design* (2003), ch. 2 "Ubiquitous Language" ("a change in the
UBIQUITOUS LANGUAGE is a change to the model"); Evans, *DDD Reference* (2015);
`docs/agents/domain.md`.

**C3. Test behaviour through the public interface.** A test calls the highest available
seam and asserts an outcome a user of that seam would notice. Test doubles replace only
adapter ports, and the ports are exactly the unmanaged dependencies (external systems the
code does not own). Managed dependencies, such as the database, run for real. When a test
needs a double where no port exists, the new seam is built as a real port, not as a
test-only hook.
Why: tests that follow implementation details break on every refactor and protect nothing.
Source: Khorikov, *Unit Testing Principles, Practices, and Patterns* (2020), ch. 4, §4.1.2
"Resistance to refactoring", ch. 8, §8.2 (managed vs. unmanaged dependencies); Google
eng-practices (Tests); Spec 0029, Testing Decisions (port rule).

**C4. Every test asserts a domain outcome.** Each test method ends in at least one
assertion about the result in domain terms (what was created, changed, refused), not only
"no exception thrown".
Why: a test without a domain assertion keeps coverage up while catching nothing. The
diff-based mutation gate covers only changed lines, and Moodle's `phpunit.xml` sets
`beStrictAboutTestsThatDoNotTestAnything="false"`, so review carries the rest.
Source: Spec 0029, Testing Decisions; Google eng-practices (Tests: "Will the tests
actually fail when the code is broken?").

**C5. Fail loud; recover only by decision.** A failure surfaces to the caller with its
cause. A fallback path exists only where an ADR or spec names it.
Why: a silent fallback hides data loss and makes the next bug undiagnosable.
Source: Hunt/Thomas, *The Pragmatic Programmer*, 20th Anniversary Ed., Tip 38 "Crash
Early" (Topic 24); Moodle coding style, Exceptions. "Fallback only by ADR" is a project
tightening (ADR 0023).

**C6. Comments say why.** An inline comment records intent or a constraint the code cannot
express, in its own words. An interface comment (docblock) states what a method or class
provides, so callers need not read the body. Tracker references (`#494`, `MDL-…`) belong in
commits and PRs; in code they appear only in a `TODO` comment, which names the full issue
URL (`TODO https://github.com/<owner>/<repo>/issues/123`). Existing
references are removed when the code around them is touched (C8).
Why: what-comments in a body go stale silently; interface comments are what makes an
abstraction usable; a bare issue number sends the reader elsewhere for the reason the
comment should give.
Source: Google eng-practices (Comments); GitLab development guidelines (Code comments);
Moodle coding style (Inline comments: tracker references only in TODOs); Ousterhout, *A
Philosophy of Software Design* (1st ed., 2018), ch. 12 §12.1 and ch. 13 §§13.5–13.6.

**C7. Prefer deep modules.** A new class or function hides a real decision behind a small
interface; a pass-through that only forwards calls is merged into its caller.
Why: shallow layers add names to learn without removing complexity.
Source: Ousterhout, *A Philosophy of Software Design*, ch. 4 (deep modules), ch. 7
(pass-through methods).

**C8. Boy-scout what you touch; clean up larger on events.** A change leaves each touched
unit cleaner than it found it; small cleanups ride along in the same change. Larger cleanup
starts on an event, never on a schedule:
- a hotspot (high change frequency overlapping high complexity),
- the third copy of a pattern (Rule of Three),
- a ticket that starts on the file ranked first in the gate failure report since the last
  release tag.

When an event fires, an architecture review of that file comes before the next feature in
it, and the cleanup goes in its own commit or PR.
Why: event triggers spend cleanup effort where change actually happens; separate cleanup
changes keep feature diffs reviewable.
Source: Martin, *Clean Code* (2008), ch. 1, p. 14 "The Boy Scout Rule"; Fowler,
*Refactoring*, 2nd ed. (2018), ch. 2 "When Should We Refactor?" (Rule of Three; Don Roberts
attribution); Tornhill, *Your Code as a Crime Scene*, 2nd ed. (2024), ch. "Discover
Hotspots" (§ "Intersect Complexity and Effort"), ch. "Architectural Reviews: Support
Redesigns with Data"; Google eng-practices (Small CLs: refactorings in a separate CL); ADR
0029 (Consequences).

**C9. Every ignore entry carries its reason.** A suppression (inline ignore, baseline entry,
mutant ignore list, dependency-rule skip) states in the same entry why the finding does not
apply, specifically enough that a reviewer can disagree. A temporary suppression (something
is meant to be fixed later) also names the issue that fixes it.
Why: an unexplained ignore is indistinguishable from a hidden defect.
Source: ADR 0029 (equivalent mutants); Spec 0029, user story 35.

**C10. Record hard-to-reverse decisions as ADRs.** A choice that constrains future changes
(contract, storage, dependency, layer) gets an ADR before the code lands; the code links it.
Why: the reason behind a choice is what a later reader cannot reconstruct.
Source: Nygard, "Documenting Architecture Decisions" (Cognitect blog, 15.11.2011), for
decisions affecting "structure, non-functional characteristics, dependencies, interfaces, or
construction techniques". "Before the code lands" and the code link are project additions.

**C11. Solve today's problem.** Build the generality the current change needs; an option,
parameter or extension point without a present caller waits until one exists.
Why: speculative generality costs reading and testing effort for a future that rarely
arrives as predicted.
Source: Google eng-practices (Complexity: "solve the problem they know needs to be solved
now").

**C12. Docs change with the code.** A change that alters how something is used, built,
tested or released updates the matching docs in the same change, or states in the PR why
none is needed.
Why: docs that lag the code mislead the next reader, human or agent.
Source: Google eng-practices (Documentation); GitLab code review checklist.

**C13. Test code is held to production standards.** A test's title matches the scenario its
setup and assertions actually exercise, and test code carries no complexity the scenario
does not need.
Why: a misnamed or convoluted test passes review while covering something else.
Source: GitLab testing best practices ("Match each example to its scenario"); Google
eng-practices (Tests: "tests are also code that has to be maintained").

**C14. Separate commands from queries.** A function either changes state or returns
information, not both. Read-only functions and tools (`get_*`, `list_*`) never write.
Exception: an atomic create may return the new record's id.
Why: combining an action and a query makes the call harder to understand, and a read that
writes surprises every caller that only meant to look.
Source: Martin, *Clean Code* (2008), ch. 3, "Command Query Separation", p. 45.

**C15. Code smells.** Each smell is a judgement call, never a hard violation; a rule above
wins where it endorses what a smell would flag. Smell → fix:
- **Mysterious Name**: the name doesn't reveal what it does or holds → rename; if no honest
  name comes, the design is murky.
- **Duplicated Code**: the same logic shape in more than one place → extract it, call it
  from both.
- **Feature Envy**: a method reaches into another object's data more than its own → move it
  onto the data it envies.
- **Data Clumps**: the same fields or parameters keep travelling together → bundle them
  into one type.
- **Primitive Obsession**: a primitive or string stands in for a domain concept → give the
  concept its own small type.
- **Repeated Switches**: the same switch or if-cascade on the same type recurs → replace with
  polymorphism or one shared map.
- **Shotgun Surgery**: one logical change forces scattered edits → gather what changes
  together into one module.
- **Divergent Change**: one module changes for several unrelated reasons → split it by
  reason.
- **Speculative Generality**: abstraction or hooks for needs nobody has (C11) → inline it
  back.
- **Message Chains**: long `a.b().c().d()` navigation → hide the walk behind one method.
- **Middle Man**: a class that mostly delegates (C7) → call the real target directly.
- **Refused Bequest**: a subclass ignores most of what it inherits → use composition.

Source: Fowler, *Refactoring*, 2nd ed. (2018), ch. 3 "Bad Smells in Code"; the same list is
the smell baseline of the `/code-review` skill.

---

## Part 2 — Moodle plugins (any Moodle plugin)

Adopt together with Part 1. Written for any Moodle plugin; `<component>` is the plugin's
Frankenstyle name (for example `local_coursepilot`).

**M1. Code prose is English.** Identifiers and test titles are English; the plugin ships only
`lang/en/`. The gate checks comments and docblocks (`english-comment`).
Why: Moodle is international; reviewers and contributors read the code, and translations
come through AMOS after approval.
Source: Moodle plugin contribution checklist (English, Strings).

**M2. User-visible text and errors use language strings.** Every text a user sees comes
from `get_string()` (or `{{#str}}` in templates) with a key in `lang/en/<component>.php`.
Strings are written in sentence case, carry no meaningful leading or trailing whitespace,
and the string file stays pure data (`$string['key'] = 'value';`, no concatenation or
heredoc). User-visible failures throw `moodle_exception` (or a subclass) with a string key;
parameter errors use `invalid_parameter_exception`; programming errors use
`coding_exception` with English text. Internal errors and protocol error descriptions (for
example OAuth `error_description`) stay English and untranslated. Either reference form
(`\moodle_exception` or a `use` import) is fine.
Why: language strings let AMOS translate what the user sees; AMOS only parses pure-data
string files.
Source: Moodle coding style (Exceptions, Namespaces, Language strings); Moodle plugin
contribution checklist (Strings); ADR 0024.

**M3. Page scripts only wire.** A page script loads config, checks access, reads
parameters, calls one class and renders. Every branch with a domain decision lives in a
tested class.
Why: logic in a page script can only be tested through the browser; in a class it is a
unit test away.
Source: Moodle Output API docs (logic before the renderable, logic-free renderers and
templates); extended to page scripts as a project rule (Spec 0029, user stories 17–18).

**M4. Pages and forms follow Moodle's security guidelines.** A page script, after
`require_login()`:
- checks the capability before showing a widget and again before acting on it;
- reads input through a moodleform with `setType()` per field, or through
  `required_param()`/`optional_param()` with a `PARAM_*` type, grouped at the top, never
  from `$_GET`, `$_POST` or `$_REQUEST`;
- acts on submitted data only on POST with a valid sesskey (`data_submitted() &&
  confirm_sesskey()`, or the moodleform);
- asks for confirmation before destroying a large amount of data;
- escapes output: `s()` for plain text, `format_string()` for names, `format_text()` for
  rich text; in Mustache `{{ }}`, and `{{{ }}}` only for HTML already cleaned by
  `format_text()`.
Why: Moodle's approval review treats any breach as a blocker; moodle-cs checks only that
`require_login()` is present.
Source: Moodle security guidelines (summary); Moodle plugin contribution checklist
(Security).

**M5. Every external function checks context and capability first.** Each external
function calls `validate_context()` and checks its capability, directly or through a shared
resolver, before reading or writing data in that context.
Why: web service clients reach data only through external functions; one that skips the
check exposes data of every course on the site.
Source: Moodle dev docs, "Writing a new service" (`validate_context()` required in all
external functions). moodle-cs does not check this.

**M6. Test external functions as a client calls them.** A test calls `execute()`, then
`clean_returnvalue()` on the result, and covers the missing-capability case.
Why: this is the path a client takes, including parameter and return validation.
Source: Moodle dev docs, "Testing external functions"; Spec 0029, Testing Decisions.
Coverage metadata (`#[CoversClass]`) is enforced by moodle-cs `moodle.PHPUnit.TestCaseCovers`
in the gate, not here.

**M7. Database access goes through the DML API and works on every supported database.**
Custom SQL uses `$DB` with placeholders (`?` or `:name`), never concatenated values, and
avoids engine-specific syntax; where SQL differs, use the `$DB->sql_*()` helpers.
Why: SQL that fails on PostgreSQL blocks approval even when it works on MySQL or MariaDB.
Source: Moodle plugin contribution checklist (Cross-DB compatibility, approval blockers);
Moodle security guidelines (SQL injection).

**M8. Settings live in the plugin's config.** Admin settings are named `<component>/<name>`
in `settings.php` and read with `get_config('<component>', '<name>')`; code writes them with
`set_config(..., '<component>')`.
Why: `config_plugins` avoids `$CFG` bloat and name collisions with other plugins.
Source: Moodle plugin contribution checklist (Settings storage).

**M9. Write actions raise Moodle events.** Every action that changes data triggers a Moodle
event, through the core API that raises it (for example `update_moduleinfo()`) or a plugin
event under `classes/event/`. Read-only functions and page views raise none.
Why: events feed the logs and other plugins' observers; a write without an event is
invisible to both.
Source: Moodle security guidelines ("Log every request"); maintainer decision (#656: writes
only).

**M10. Internal functions take explicit, typed parameters.** A PHP function lists each
option as its own typed parameter with a default, or takes a small options class, instead
of a generic `$options` array. Magic methods (`__get`, `__call`, …) need a written reason
(C9). External function parameters follow the external API definitions and are exempt.
Why: typed parameters document themselves and let static analysis check every call.
Source: Moodle coding style (Using arrays for options as arguments; Magic methods).

**M11. Styles are scoped to the plugin.** Every selector in the plugin's `styles.css` starts
with a plugin-scoped class: the page body class Moodle adds (`.path-mod-<name>`, …) or a
class prefixed with the plugin's name.
Why: Moodle concatenates every plugin's `styles.css` and serves it on every page; an
unscoped selector restyles pages far outside the plugin.
Source: Moodle plugin contribution checklist (CSS styles).

---

## Part 3 — Coursepilot (`local_coursepilot`)

**P1. Place a class by what it does, not by what it uses.** The four layers are Entry
(page scripts, MCP endpoint, dispatcher, tool registry), Tools (`external`), Domain modules
(catalog, history, quiz, context area, material store, storage anchor, OAuth) and Adapters
(WebDAV, storage ports). Logic a second tool needs moves down into a domain module; a domain
module that needs a tool's behaviour gets that behaviour moved into the module. When a new
root class fits two layers, the author picks one in the deptrac class list with a one-line
reason beside the entry; review may overrule it.
Why: the dependency checker forbids the back-edges, but only judgement picks the right home;
the three measured back-edges (catalog, history, WebDAV → external) came from placing logic
where it was first needed.
Source: ADR 0030.

**P2. Module instances change through the form path.** Create and update modules via
`add_moduleinfo()`/`update_moduleinfo()`. Direct table writes are reserved for what Moodle
has no form field for (positions, quiz slot structure). Backup XML is a creation path for
supported activity types only.
Why: the form path raises `course_module_updated` (M9), which the change history depends on,
and cannot write broken `availability` JSON.
Source: ADR 0016, ADR 0028, ADR 0018.

**P3. Contract tests stay as they are.** Privacy-surface, install-smoke and similar invariant
tests change only together with the contract they guard, and never count as prune
candidates.
Why: they protect invariants and need not kill mutants.
Source: Spec 0029, user story 33.

**P4. The tool contract and skill corpus are English.** M1 extends to tool parameters,
return keys, tool descriptions, handshake instructions and the skill corpus. `lang/de/` is a
development translation; the release build leaves it out.
Why: the tool contract is final once published, and the AI relays it in the teacher's
language.
Source: ADR 0024 (incl. addenda 2026-10-04); `scripts/build-native-release.js`.

**P5. Use glossary terms in English form.** Each `CONTEXT.md` entry used in code carries a
`Code:` line with its fixed English rendering; classes, tool parameters and return keys use
exactly that rendering. The line is added when a term first reaches code or its entry is
next touched.
Why: the tool contract is English (ADR 0024) while the glossary is German; one fixed
rendering per entry keeps the translation single instead of letting one concept collect
several English names.
Source: CONTEXT.md; ADR 0024; C2; Evans, *Domain-Driven Design*, ch. 2 ("Translation
blunts communication").

**P6. Teacher-facing text reads well in Codex.** A change to tool descriptions, handshake
instructions or the skill corpus is checked against how Codex presents it, not only Claude.
Why: Codex is the product's primary client for teachers (Codex-First).
Source: CONTEXT.md (Codex-First); CLAUDE.md.

**P7. Entry scripts stay out of the coverage denominator only under M3.** Page scripts and
admin settings are excluded from coverage; a script that gains a domain branch moves it into
a class in the same change.
Why: the exclusion is honest only while the scripts hold no logic.
Source: Spec 0029, user stories 17–18.

Planned gate checks that will retire rules here (C1): M5 → #686, M7 on PostgreSQL → #687.

---

## Not in this file (enforced by the gate)

Coverage ratchet and per-file 90 %, CRAP ≤ 8 per changed method, surviving mutants on changed
lines, moodle-cs (including coverage metadata on every test, boilerplate and
`@copyright`, naming, `require_login()` in page scripts, forbidden functions such as `eval`
and `unserialize`, and the TODO issue format), phpdoc, savepoints, Mustache,
ESLint, PHPStan level and baseline, layer dependencies and the class list. See ADR 0029, ADR
0030 and Spec 0029.
