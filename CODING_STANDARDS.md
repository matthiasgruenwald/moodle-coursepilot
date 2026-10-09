# Coding Standards

Adopted with the association developers (#657). Sources and their checks:
`docs/research/coding-standards-quellen.md`.

This file holds only the rules **no tool enforces** and a reviewer can check on a diff.
Architecture goals and structural decisions live elsewhere: ADRs in `docs/adr/`, and the
association-wide architecture guidelines (#704). The gate (ADR 0029, ADR 0030) enforces
coverage, CRAP, mutation, moodle-cs, phpdoc, PHPStan and the layer dependencies; when the
gate is red, fix the code until it is green. **A rule that a tool can check belongs in the
tool** (A1), and leaves this file the day the tool checks it.

## How to read a rule

Each rule reads: **rule** → why → source, with two tags:

- **Scope:** `all` (any project, any stack), `Moodle` (any Moodle plugin), `AI` (any project
  with an MCP access or another AI interface). A project adopts the scopes that apply to it;
  `<component>` stands for a Moodle plugin's Frankenstyle name.
- **Level:**
  - **Must:** a breach blocks the merge.
  - **Should:** a deviation is allowed when the PR states why.
  - **Hint:** a judgement call; the reviewer names it, the author decides.

Rules are grouped by topic. The letter names the topic, the number is stable: a rule that
leaves this file leaves a gap (`→ gate, #nnn`), and numbers are never reused. A reviewer cites
the rule by its number (`S2`, `T4`, `K6`).

Topics, in order of what matters most to us (security and privacy, data integrity, Moodle
approval, maintainability, portability):
**S** Security and failure · **D** Data and Moodle integration · **T** Tests · **E** Design ·
**N** Language and naming · **U** User interface · **A** Way of working · **K** AI interface
(add-on part).

---

## S — Security and failure

**S1. Fail loud; recover only by decision.** `all` · **Must**
A failure surfaces to the caller with its cause. A fallback path exists only where an ADR or
spec names it.
Why: a silent fallback hides data loss and makes the next bug undiagnosable.
Source: Hunt/Thomas, *The Pragmatic Programmer*, 20th Anniversary Ed., Tip 38 "Crash Early"
(Topic 24); Moodle coding style, Exceptions. "Fallback only by ADR" is a project tightening
(ADR 0023).

**S2. Pages and forms follow Moodle's security guidelines.** `Moodle` · **Must**
A page script, after `require_login()`:
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

**S3. Every external function checks context and capability first.** `Moodle` · **Must**
Each external function calls `validate_context()` and checks its capability, directly or
through a shared resolver, before reading or writing data in that context.
Why: web service clients reach data only through external functions; one that skips the
check exposes data of every course on the site.
Source: Moodle dev docs, "Writing a new service" (`validate_context()` required in all
external functions). moodle-cs does not check this; planned gate check #686.

---

## D — Data and Moodle integration

**D1. Change core data through core APIs.** `Moodle` · **Must**
Data that Moodle core owns changes through the core function made for it, for example
module instances through `add_moduleinfo()`/`update_moduleinfo()`. Direct writes to core
tables are reserved for what core offers no function for (for example section positions
or quiz slot structure), and each such write names its reason (A2).
Why: core functions keep dependent data consistent, raise the matching events (D3) and
validate what they store, such as `availability` JSON; a hand-written copy has to be
maintained by the plugin and risks broken records.
Source: Moodle course API (`course/modlib.php`); ADR 0016.

**D2. Database access goes through the DML API and works on every supported database.**
`Moodle` · **Must**
Custom SQL uses `$DB` with placeholders (`?` or `:name`), never concatenated values, and
avoids engine-specific syntax; where SQL differs, use the `$DB->sql_*()` helpers. It runs
on every database Moodle supports; as of Moodle 5.0 (unchanged through 5.2): MySQL,
MariaDB, PostgreSQL, Microsoft SQL Server and Amazon Aurora MySQL.
Why: SQL that fails on one supported database blocks approval even when it works on the
others.
Source: Moodle plugin contribution checklist (Cross-DB compatibility, approval blockers);
Moodle security guidelines (SQL injection); `admin/environment.xml` (Moodle 5.0–5.2 database
vendors). CI tests MariaDB, and PostgreSQL for Moodle 5.2 and 5.3 (#679); #687 tracks
the rest.

**D3. Write actions raise Moodle events.** `Moodle` · **Must**
Every action a person triggers that changes domain data, and every security-relevant
action (a connection granted or revoked, an access permission changed), raises a Moodle
event: through the core API that raises it (D1) or a plugin event under `classes/event/`.
Technical bookkeeping (caches, token rotation, internal history rows, cleanup tasks) and
read-only functions raise none.
Why: events feed the logs and other plugins' observers; they show who changed what and
when, which is what debugging and tracing an AI-triggered change need. Bookkeeping events
would only add noise.
Source: Moodle security guidelines ("Log every request"); association decision (#657:
scope).

**D4. Settings live in the plugin's config.** `Moodle` · **Should**
Admin settings are named `<component>/<name>` in `settings.php` and read with
`get_config('<component>', '<name>')`; code writes them with `set_config(...,
'<component>')`.
Why: `config_plugins` avoids `$CFG` bloat and name collisions with other plugins.
Source: Moodle plugin contribution checklist (Settings storage).

---

## T — Tests

**T1. Test behaviour through the public interface.** `all` · **Should**
A test calls the highest available seam and asserts an outcome a user of that seam would
notice. Test doubles replace only adapter ports, and the ports are exactly the unmanaged
dependencies (external systems the code does not own). Managed dependencies, such as the
database, run for real. When a test needs a double where no port exists, the new seam is
built as a real port, not as a test-only hook.
Why: tests that follow implementation details break on every refactor and protect nothing.
Source: Khorikov, *Unit Testing Principles, Practices, and Patterns* (2020), ch. 4, §4.1.2
"Resistance to refactoring", ch. 8, §8.2 (managed vs. unmanaged dependencies); Google
eng-practices (Tests); Spec 0029, Testing Decisions (port rule).

**T2. Every test asserts a domain outcome.** `all` · **Must**
Each test method ends in at least one assertion about the result in domain terms (what was
created, changed, refused), not only "no exception thrown".
Why: a test without a domain assertion keeps coverage up while catching nothing. The
diff-based mutation gate covers only changed lines, and Moodle's `phpunit.xml` sets
`beStrictAboutTestsThatDoNotTestAnything="false"`, so review carries the rest.
Source: Spec 0029, Testing Decisions; Google eng-practices (Tests: "Will the tests
actually fail when the code is broken?").

**T3. Test code is held to production standards.** `all` · **Should**
A test's title matches the scenario its setup and assertions actually exercise, and test
code carries no complexity the scenario does not need.
Why: a misnamed or convoluted test passes review while covering something else.
Source: GitLab testing best practices ("Match each example to its scenario"); Google
eng-practices (Tests: "tests are also code that has to be maintained").

**T4. Contract tests change only with their contract.** `all` · **Must**
Tests that guard an invariant (privacy surface, install smoke, published interface) change
only together with the contract they guard, and never count as prune candidates.
Why: they protect invariants and need not kill mutants.
Source: Spec 0029, user story 33.

**T5. Test external functions as a client calls them.** `Moodle` · **Should**
A test calls `execute()`, then `clean_returnvalue()` on the result, and covers the
missing-capability case.
Why: this is the path a client takes, including parameter and return validation.
Source: Moodle dev docs, "Testing external functions"; Spec 0029, Testing Decisions.
Coverage metadata (`#[CoversClass]`) is enforced by moodle-cs `moodle.PHPUnit.TestCaseCovers`
in the gate, not here.

---

## E — Design

**E1. Prefer deep modules.** `all` · **Should**
A new class or function hides a real decision behind a small interface; a pass-through that
only forwards calls is merged into its caller.
Why: shallow layers add names to learn without removing complexity.
Source: Ousterhout, *A Philosophy of Software Design*, ch. 4 (deep modules), ch. 7
(pass-through methods).

**E2. Solve today's problem.** `all` · **Should**
Build the generality the current change needs; an option, parameter or extension point
without a present caller waits until one exists.
Why: speculative generality costs reading and testing effort for a future that rarely
arrives as predicted.
Source: Google eng-practices (Complexity: "solve the problem they know needs to be solved
now").

**E3. Separate commands from queries.** `all` · **Should**
A function either changes state or returns information, not both. Read-only functions
(`get_*`, `list_*`) never write. Exception: an atomic create may return the new record's id.
Why: combining an action and a query makes the call harder to understand, and a read that
writes surprises every caller that only meant to look.
Source: Martin, *Clean Code* (2008), ch. 3, "Command Query Separation", p. 45.

**E4. Page scripts only wire.** `Moodle` · **Should**
A page script loads config, checks access, reads parameters, calls one class and renders.
Every branch with a domain decision lives in a tested class; a script that gains one moves
it into a class in the same change. Only under this condition may page scripts and admin
settings be excluded from coverage (A2).
Why: logic in a page script can only be tested through the browser; in a class it is a
unit test away. The coverage exclusion is honest only while the scripts hold no logic.
Source: Moodle Output API docs (logic before the renderable, logic-free renderers and
templates); extended to page scripts as a project rule (Spec 0029, user stories 17–18).

**E5. Internal functions take explicit, typed parameters.** `Moodle` · **Should**
A PHP function lists each option as its own typed parameter with a default, or takes a
small options class, instead of a generic `$options` array. Magic methods (`__get`,
`__call`, …) need a written reason (A2). External function parameters follow the external
API definitions and are exempt.
Why: typed parameters document themselves and let static analysis check every call.
Source: Moodle coding style (Using arrays for options as arguments; Magic methods).

**E6. Code smells.** `all` · **Hint**
Each smell is a judgement call, never a hard violation; a rule above wins where it endorses
what a smell would flag. Smell → fix:
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
- **Speculative Generality**: abstraction or hooks for needs nobody has (E2) → inline it
  back.
- **Message Chains**: long `a.b().c().d()` navigation → hide the walk behind one method.
- **Middle Man**: a class that mostly delegates (E1) → call the real target directly.
- **Refused Bequest**: a subclass ignores most of what it inherits → use composition.

Source: Fowler, *Refactoring*, 2nd ed. (2018), ch. 3 "Bad Smells in Code"; the same list is
the smell baseline of the `/code-review` skill.

---

## N — Language and naming

**N1. Name things with the domain's words.** `all` · **Should**
Identifiers, test titles and messages use the term the project glossary defines for that
concept, one term per concept. New or changed terms go into the glossary first (via the
`/domain-modeling` skill or equivalent); when a term changes, the classes, methods and keys
that carry it are renamed with it. When the glossary and the code use different languages,
each glossary entry used in code carries a `Code:` line with its one fixed rendering, and
code uses exactly that rendering; the line is added when a term first reaches code or its
entry is next touched.
Why: a shared language lets readers, reviewers and agents find a concept by its name; one
fixed rendering per entry keeps a translation single instead of letting one concept
collect several names.
Source: Evans, *Domain-Driven Design* (2003), ch. 2 "Ubiquitous Language" ("a change in the
UBIQUITOUS LANGUAGE is a change to the model"; "Translation blunts communication"); Evans,
*DDD Reference* (2015); `docs/agents/domain.md`.

**N2. Comments say why.** `all` · **Should**
An inline comment records intent or a constraint the code cannot express, in its own words.
An interface comment (docblock) states what a method or class provides, so callers need not
read the body. Tracker references (`#494`, `MDL-…`) belong in commits and PRs; in code they
appear only in a `TODO` comment, which names the full issue URL
(`TODO https://github.com/<owner>/<repo>/issues/123`). Existing references are removed when
the code around them is touched (A5).
Why: what-comments in a body go stale silently; interface comments are what makes an
abstraction usable; a bare issue number sends the reader elsewhere for the reason the
comment should give.
Source: Google eng-practices (Comments); GitLab development guidelines (Code comments);
Moodle coding style (Inline comments: tracker references only in TODOs); Ousterhout, *A
Philosophy of Software Design* (1st ed., 2018), ch. 12 §12.1 and ch. 13 §§13.5–13.6.

**N3. Code and published interface are English.** `Moodle` · **Must**
Identifiers, comments, docblocks and test titles are English, and so is everything the
plugin publishes as an interface: external function parameters, return keys and
descriptions. The plugin ships only `lang/en/`.
Why: Moodle is international; reviewers and contributors read the code, a published
interface is final once clients depend on it, and translations come through AMOS after
approval.
Source: Moodle plugin contribution checklist (English, Strings); ADR 0024. Planned gate
check for comments: #685.

**N4. User-visible text and errors use language strings.** `Moodle` · **Must**
Every text a user sees comes from `get_string()` (or `{{#str}}` in templates) with a key in
`lang/en/<component>.php`. Strings are written in sentence case, carry no meaningful leading
or trailing whitespace, and the string file stays pure data (`$string['key'] = 'value';`,
no concatenation or heredoc). User-visible failures throw `moodle_exception` (or a subclass)
with a string key; parameter errors use `invalid_parameter_exception`; programming errors
use `coding_exception` with English text. Internal errors and protocol error descriptions
(for example OAuth `error_description`) stay English and untranslated. Either reference
form (`\moodle_exception` or a `use` import) is fine.
Why: language strings let AMOS translate what the user sees; AMOS only parses pure-data
string files.
Source: Moodle coding style (Exceptions, Namespaces, Language strings); Moodle plugin
contribution checklist (Strings); ADR 0024.

---

## U — User interface

**U1. Use Moodle's own building blocks first.** `Moodle` · **Should**
Pages and templates are built from what Moodle and its default theme already provide: core
templates and output components, and the Bootstrap utility and component classes of the
Boost theme. Own CSS is written only for what these cannot express.
Why: core building blocks follow the site's theme, accessibility work and Moodle upgrades
for free; own CSS has to be maintained by the plugin and breaks when the theme changes.
Source: Moodle Output API docs; Moodle Component Library (Bootstrap in Boost); association
decision (#657).

**U2. Own styles are scoped to the plugin.** `Moodle` · **Must**
Every selector in the plugin's `styles.css` starts with a plugin-scoped class: the page body
class Moodle adds (`.path-mod-<name>`, …) or a class prefixed with the plugin's name.
Why: Moodle concatenates every plugin's `styles.css` and serves it on every page; an
unscoped selector restyles pages far outside the plugin.
Source: Moodle plugin contribution checklist (CSS styles).

---

## A — Way of working

**A1. Tooling owns every checkable rule.** `all` · **Should**
When a rule can be checked mechanically, add the check to the gate and delete the prose
rule.
Why: agents treat prose as guidance and checks as law; two homes for one rule drift apart.
Source: ADR 0029 (Context); Spec 0029, user stories 40–41.

**A2. Every ignore entry carries its reason.** `all` · **Must**
A suppression (inline ignore, baseline entry, mutant ignore list, dependency-rule skip,
coverage exclusion) states in the same entry why the finding does not apply, specifically
enough that a reviewer can disagree. A temporary suppression (something is meant to be
fixed later) also names the issue that fixes it.
Why: an unexplained ignore is indistinguishable from a hidden defect.
Source: ADR 0029 (equivalent mutants); Spec 0029, user story 35.

**A3. Record hard-to-reverse decisions as ADRs.** `all` · **Should**
A choice that constrains future changes (contract, storage, dependency, layer) gets an ADR
before the code lands; the code links it.
Why: the reason behind a choice is what a later reader cannot reconstruct.
Source: Nygard, "Documenting Architecture Decisions" (Cognitect blog, 15.11.2011), for
decisions affecting "structure, non-functional characteristics, dependencies, interfaces, or
construction techniques". "Before the code lands" and the code link are project additions.

**A4. Docs change with the code.** `all` · **Should**
A change that alters how something is used, built, tested or released updates the matching
docs in the same change, or states in the PR why none is needed.
Why: docs that lag the code mislead the next reader, human or agent.
Source: Google eng-practices (Documentation); GitLab code review checklist.

**A5. Boy-scout what you touch; clean up larger on events.** `all` · **Should**
A change leaves each touched unit cleaner than it found it; small cleanups ride along in the
same change. Larger cleanup starts on an event, never on a schedule:
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

---

## K — AI interface (add-on part)

Applies only to projects with an MCP access or another AI interface. *AI text* below means
everything written for the model to read: tool descriptions, handshake instructions and the
skill corpus.

**K1. Deterministic before AI.** `AI` · **Should**
What follows fixed rules (validating, resolving ids, computing, formatting, checking
preconditions) is done in code; the model gets only what needs judgement.
Why: code decides the same way every time and can be tested; a model decision costs
context and may differ from run to run.
Source: Anthropic, "Building effective agents" (19.12.2024: "find the simplest solution
possible, and only increasing complexity when needed"); OWASP Top 10 for LLM Applications
2025, LLM01 (deterministic validation of outputs).

**K2. Guardrails live in code, not in text.** `AI` · **Must**
A limit on what the model may do is enforced by permissions and code, for example separate
read and write tools or a capability check; a sentence in AI text ("do not write …") is
never the only barrier.
Why: the model can misread, ignore or be talked out of an instruction; a permission check
cannot.
Source: OWASP Top 10 for LLM Applications 2025, LLM06 Excessive Agency ("Implement
authorization in downstream systems rather than relying on an LLM to decide if an action is
allowed"); `CONTEXT.md` (MCP-Profiltrennung).

**K3. Input from the model is untrusted.** `AI` · **Must**
Tool parameters are validated like user input. Content the tool reads from courses, files or
other sources may carry instructions (indirect prompt injection); the code never treats it
as a command, and where it is passed back to the model it is marked as data.
Why: the model's input is shaped by whatever text reached its context, including text an
attacker placed in a course.
Source: MCP specification 2025-11-25, Tools, Security considerations ("Servers MUST:
Validate all tool inputs"); OWASP Top 10 for LLM Applications 2025, LLM01 Prompt Injection.

**K4. Write tools are guarded and labelled.** `AI` · **Must**
A tool that destroys or changes a large amount of data offers a preview (`dry_run`) or
requires an explicit confirmation step. Every tool declares truthfully whether it only
reads or writes (`readOnlyHint`, `destructiveHint` in MCP), matching E3.
Why: clients decide on confirmation prompts from these labels; MCP assumes a tool writes
destructively when no label is given.
Source: MCP specification 2025-11-25, Tools (human in the loop, confirmation prompts) and
schema `ToolAnnotations`; OWASP Top 10 for LLM Applications 2025, LLM06 ("require a human
to approve high-impact actions").

**K5. Work without personal data where possible.** `AI` · **Should**
Tools and skills solve their task without personal data whenever they can, for example with
ids, pseudonyms or aggregates instead of names.
Why: data that never reaches the model cannot leak from it.
Source: GDPR Art. 5(1)(c) (data minimisation), Art. 25 (data protection by design and by
default).

**K6. Personal data flows only behind a technical release.** `AI` · **Must**
A tool that can do its task without personal data returns none. Where it must return some,
code releases it only behind a fixed switch or marking, never on the model's judgement.
What code cannot detect, the AI text covers: it tells the model not to reuse personal data
and to point it out to the user.
Why: the model is the one party that cannot be relied on to keep a secret.
Source: GDPR Art. 25(2) ("only personal data which are necessary for each specific purpose
… are processed"); OWASP Top 10 for LLM Applications 2025, LLM02 Sensitive Information
Disclosure (least privilege); ADR 0011.

**K7. Spend the model's context sparingly.** `AI` · **Should**
Large data (images, files, exports) travels another way, such as a download link or the
storage location, not through a tool response. Responses carry only the fields the task
needs and are limited or paged by default.
Why: everything in a tool response fills the model's context and crowds out the task.
Source: Anthropic, "Writing effective tools for agents — with agents" (11.09.2025:
"return only high signal information"; "pagination, range selection, filtering, and/or
truncation with sensible default parameter values").

**K8. Errors tell the model its next step.** `AI` · **Should**
A tool error names the cause and what the model can do next (correct a parameter, ask the
user, stop), in addition to S1.
Why: an actionable error lets the model correct itself instead of guessing.
Source: MCP specification 2025-11-25, Tools, Error handling ("actionable feedback that
language models can use to self-correct"); Anthropic, "Writing effective tools for agents"
(2025).

**K9. AI text is English.** `AI` · **Should**
Tool descriptions, handshake instructions and the skill corpus are English; the model
answers the user in the user's language.
Why: the interface is final once published, and one base language keeps it single.
Source: ADR 0024 (incl. addenda 2026-10-04).

**K10. AI text is checked in Codex and Claude.** `AI` · **Should**
A change to AI text is checked against how both Codex and Claude present and follow it.
Why: teachers use both clients; a text tuned to one can misfire in the other.
Source: `CONTEXT.md` (Codex-First); association decision (#657).

---

## Not in this file

**Enforced by the gate:** coverage ratchet and per-file 90 %, CRAP ≤ 8 per changed method,
surviving mutants on changed lines, moodle-cs (including coverage metadata on every test,
boilerplate and `@copyright`, naming, `require_login()` in page scripts, forbidden functions
such as `eval` and `unserialize`, and the TODO issue format), phpdoc, savepoints, Mustache,
ESLint, PHPStan level and baseline, layer dependencies and the class list. See ADR 0029, ADR
0030 and Spec 0029. Planned gate checks that will retire rules here (A1): N3 comments →
#685, S3 → #686, D2 on PostgreSQL → #687.

**Architecture:** layer placement in Coursepilot (ADR 0030), MCP access vs. Moodle AI access,
moving large data around the model's context — see the ADRs and the architecture
guidelines (#704).
