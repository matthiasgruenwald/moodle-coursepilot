# Coding Standards

> **DRAFT for discussion (#656).** Nothing here is decided. Each rule is a proposal with its
> source; `Open:` marks a real alternative the developers settle before adoption. Once
> decided, the `Open:` lines go and the file becomes the Standards axis of `/code-review`.

This file holds only the rules **no tool enforces**. The gate (ADR 0029, ADR 0030) enforces
coverage, CRAP, mutation, moodle-cs, phpdoc, PHPStan and the layer dependencies; when the
gate is red, fix the code until it is green. A rule that a tool can check belongs in the
tool, and leaves this file the day the tool checks it.

Each rule reads: **rule** → why → source. A reviewer cites the rule by its number (`C3`,
`P5`).

---

## Part 1 — Core (any project, any stack)

Written stack-neutral so other association projects can adopt this part unchanged.

**C1. Tooling owns every checkable rule.** When a rule can be checked mechanically, add the
check to the gate and delete the prose rule.
Why: agents treat prose as guidance and checks as law; two homes for one rule drift apart.
Source: ADR 0029 (Context); Spec 0029, user stories 40–41.

**C2. Name things with the domain's words.** Identifiers, test titles and messages use the
term the project glossary defines for that concept, one term per concept.
Why: a shared language lets readers, reviewers and agents find a concept by its name.
Source: Evans, *Domain-Driven Design* (2003), ch. 2 "Ubiquitous Language"; Evans, *DDD
Reference* (2015).

**C3. Test behaviour through the public interface.** A test calls the highest available
seam and asserts an outcome a user of that seam would notice. Test doubles replace only
existing adapter ports.
Why: tests that follow implementation details break on every refactor and protect nothing.
Source: Khorikov, *Unit Testing Principles, Practices, and Patterns* (2020), ch. 4, §4.1.2
"Resistance to refactoring"; Google eng-practices (Tests). The port rule is a project
decision (Spec 0029, Testing Decisions), not Khorikov.
Open: Khorikov's own rule is "mock unmanaged dependencies, use real managed ones". Adopt
that wording instead of the port rule? And: allow a seam added only for testing when no
port exists (today: no), or require an ADR for each?

**C4. Every test asserts a domain outcome.** Each test method ends in at least one
assertion about the result in domain terms (what was created, changed, refused), not only
"no exception thrown".
Why: a test without a domain assertion keeps coverage up while catching nothing.
Source: Spec 0029, Testing Decisions; Google eng-practices (Tests: "Will the tests
actually fail when the code is broken?"). Moodle's `phpunit.xml` sets
`beStrictAboutTestsThatDoNotTestAnything="false"`, so PHPUnit does not catch this.
Open: the diff-based mutation gate partly enforces this on changed lines. Keep the rule for
untouched code and review, or drop it once mutation runs in CI?

**C5. Fail loud; recover only by decision.** A failure surfaces to the caller with its
cause. A fallback path exists only where an ADR or spec names it.
Why: a silent fallback hides data loss and makes the next bug undiagnosable.
Source: Hunt/Thomas, *The Pragmatic Programmer*, 20th Anniversary Ed., Tip 38 "Crash
Early" (Topic 24); Moodle coding style, Exceptions. "Fallback only by ADR" is a project
tightening (ADR 0023).

**C6. Comments say why.** An inline comment records intent or a constraint the code cannot
express. An interface comment (docblock) states what a method or class provides, so callers
need not read the body.
Why: what-comments in a body go stale silently; interface comments are what makes an
abstraction usable.
Source: Google eng-practices (Comments); GitLab development guidelines (Code comments);
Moodle coding style (Inline comments); Ousterhout, *A Philosophy of Software Design*, ch.
12–13 (interface comments).
Open: Moodle's style forbids tracker references in inline comments except in TODOs. Adopt
that for `#nnn` issue numbers (many existing lines, cleaned up on touch per C8), or keep
issue numbers as a project exception?

**C7. Prefer deep modules.** A new class or function hides a real decision behind a small
interface; a pass-through that only forwards calls is merged into its caller.
Why: shallow layers add names to learn without removing complexity.
Source: Ousterhout, *A Philosophy of Software Design*, ch. 4 (deep modules), ch. 7
(pass-through methods).

**C8. Boy-scout what you touch; clean up larger on events.** A change leaves each touched
unit cleaner than it found it; small cleanups ride along in the same change. Larger cleanup
starts on an event, never on a schedule: a hotspot (high change frequency overlapping high
complexity), the third copy of a pattern (Rule of Three), or a file at the top of the gate
failure report. When an event fires, an architecture review of that file comes before the
next feature in it, and the cleanup goes in its own commit or PR.
Why: event triggers spend cleanup effort where change actually happens; separate cleanup
changes keep feature diffs reviewable.
Source: Martin, *Clean Code* (2008), ch. 1, p. 14 "The Boy Scout Rule"; Fowler,
*Refactoring*, ch. 2 "When Should We Refactor?" (Rule of Three); Tornhill, *Your Code as a
Crime Scene*, 2nd ed. (2024), ch. 4 (hotspots), ch. 10 (architectural reviews); Google
eng-practices (Small CLs: refactorings in a separate CL); ADR 0029 (Consequences).
Open: does "top of the failure report" mean rank 1 only, or the top N, and over which time
window?

**C9. Every ignore entry carries its reason.** A suppression (inline ignore, baseline entry,
mutant ignore list, dependency-rule skip) states in the same entry why the finding does not
apply, specifically enough that a reviewer can disagree.
Why: an unexplained ignore is indistinguishable from a hidden defect.
Source: ADR 0029 (equivalent mutants); Spec 0029, user story 35.
Open: should a reason also name an issue to revisit, or is the reason alone enough?

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
Open: new rule from the source check. Adopt, or treat as covered by C7?

**C12. Docs change with the code.** A change that alters how something is used, built,
tested or released updates the matching docs in the same change, or states why none is
needed.
Why: docs that lag the code mislead the next reader, human or agent.
Source: Google eng-practices (Documentation); GitLab code review checklist.
Open: new rule from the source check. Adopt?

**C13. Test code is held to production standards.** A test's title matches the scenario its
setup and assertions actually exercise, and test code carries no complexity the scenario
does not need.
Why: a misnamed or convoluted test passes review while covering something else.
Source: GitLab testing best practices ("Match each example to its scenario"); Google
eng-practices (Tests: "tests are also code that has to be maintained").
Open: new rule from the source check. Adopt?

> The `/code-review` skill already applies a fixed Fowler smell baseline (Mysterious Name,
> Duplicated Code, Feature Envy, …). This part does not restate it.
> Open: adopt the smell baseline explicitly here, or leave it as the skill's default?

---

## Part 2 — Coursepilot (`local_coursepilot`)

**P1. Place a class by what it does, not by what it uses.** The four layers are Entry
(page scripts, MCP endpoint, dispatcher, tool registry), Tools (`external`), Domain modules
(catalog, history, quiz, context area, material store, storage anchor, OAuth) and Adapters
(WebDAV, storage ports). Logic a second tool needs moves down into a domain module; a domain
module that needs a tool's behaviour gets that behaviour moved into the module.
Why: the dependency checker forbids the back-edges, but only judgement picks the right home;
the three measured back-edges (catalog, history, WebDAV → external) came from placing logic
where it was first needed.
Source: ADR 0030.
Open: when a new root class fits two layers, who decides: the author in the class-list
entry, or a review comment with a one-line reason?

**P2. Entry scripts only wire.** A page script loads config, checks access, reads
parameters, calls one class and renders. Every branch with a domain decision lives in a
tested class.
Why: entry scripts are excluded from the coverage denominator; that exclusion is honest only
while they hold no logic.
Source: Spec 0029, user stories 17–18; #334, #494 (thin shell, test the class). Project
rule: Moodle itself only requires logic-free renderers and templates (Output API docs).

**P3. Module instances change through the form path.** Create and update modules via
`add_moduleinfo()`/`update_moduleinfo()`. Direct table writes are reserved for what Moodle
has no form field for (positions, quiz slot structure). Backup XML is a creation path for
supported activity types only.
Why: the form path raises `course_module_updated`, which the change history depends on, and
cannot write broken `availability` JSON.
Source: ADR 0016, ADR 0028, ADR 0018.

**P4. Test tools through the external function.** A tool test calls the external function
(`execute()` with its parameters and return validation), a domain test calls the module's
public interface. Each test class declares `#[CoversClass]` for the classes it targets.
Why: coverage counts strictly by `#[CoversClass]`; code run only indirectly counts as
uncovered.
Source: Spec 0029, Testing Decisions, user stories 14–15; ADR 0029; Moodle dev docs,
"Testing external functions" (`execute()` + `clean_returnvalue()`).
Open: moodle-cs already warns on missing coverage metadata (`moodle.PHPUnit.TestCaseCovers`).
Run it with warnings as errors in the gate, and the `#[CoversClass]` half leaves this file
(C1). PHPUnit's `requireCoverageMetadata` would need a patched Moodle-generated
`phpunit.xml`.

**P5. Contract tests stay as they are.** Privacy-surface, install-smoke and similar invariant
tests change only together with the contract they guard, and never count as prune
candidates.
Why: they protect invariants and need not kill mutants.
Source: Spec 0029, user story 33.

**P6. Errors are Moodle exceptions with language strings.** Teacher-visible failures throw
`moodle_exception` (or a subclass) with a string key in `lang/en/local_coursepilot.php`;
parameter errors use `invalid_parameter_exception`; programming errors use
`coding_exception` with English text. Internal errors and OAuth `error_description` stay
English and untranslated.
Why: language strings let AMOS translate what the teacher sees, and the AI relays it in the
teacher's language.
Source: ADR 0024 (incl. addendum 2026-10-04); Moodle coding style, Exceptions.
Open: the code mixes `\moodle_exception` and imported `moodle_exception` (≈ 84 : 64). The
Moodle style allows both and moodle-cs checks neither. Fix one form as a project rule, or
leave it open?

**P7. Use glossary terms in English form.** Code names a concept by the English rendering of
its `CONTEXT.md` term, consistently across classes, tool parameters and return keys.
Why: the tool contract is English (ADR 0024) while the glossary is German; without a fixed
rendering one concept ends up with several English names.
Source: CONTEXT.md; ADR 0024; core rule C2.
Open: add an English column (`Code:` line) to each `CONTEXT.md` entry, or keep a separate
term map? (Evans warns that translation between domain and code language blunts it; one
fixed rendering per entry keeps that translation single.)

**P8. English base for all code prose.** Identifiers, comments, docblocks, test titles, tool
descriptions and the skill corpus are English; only `lang/de/` carries German.
Why: Marketplace requirement and international usability of the tool contract.
Source: ADR 0024.
Open: Node contract tests already assert the corpus is English (#604). Extend that check to
comments and docblocks, so this rule moves into the gate (C1)?

**P9. Teacher-facing text reads well in Codex.** A change to tool descriptions, handshake
instructions or the skill corpus is checked against how Codex presents it, not only Claude.
Why: Codex is the product's primary client for teachers (Codex-First).
Source: CONTEXT.md (Codex-First); CLAUDE.md.

**P10. Every tool checks context and capability first.** Each external function calls
`validate_context()` and checks its capability before reading or writing data in that
context.
Why: tools are the only door teachers' AI clients use; a tool that skips the check exposes
data of every course on the site.
Source: Moodle dev docs, "Writing a new service" (`validate_context()` required in all
external functions). moodle-cs does not check this for external functions.
Open: new rule from the source check. Adopt, or build a gate check (C1)?

---

## Not in this file (enforced by the gate)

Coverage ratchet and per-file 90 %, CRAP ≤ 8 per changed method, surviving mutants on changed
lines, moodle-cs, phpdoc, savepoints, Mustache, ESLint, PHPStan level and baseline, layer
dependencies and the class list. See ADR 0029, ADR 0030 and Spec 0029.
