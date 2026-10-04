---
name: quiz-and-question-bank
description: Read this when planning or implementing quizzes or creating and reorganizing question-bank categories.
---

# Reference: quiz modes and question-bank categories

Use for `coursepilot_create_quiz`, `coursepilot_update_quiz_settings`
and category creation/cleanup.

## Quiz modes

mode selects one of three documented presets. Planning defaults to
progress-check; pass it explicitly on creation. Leave mode empty for
patches changing individual settings only. Explicit timelimit overrides
its preset (layered defaults). Set the planned passing threshold
explicitly below. Never use test as a mode name, which is ambiguous with
the quiz activity itself.

| Mode | Behavior | Attempts | Grade method | Layout | Delay | Review | Planned passing threshold, explicit |
|---|---|---|---|---|---|---|---|
| mini-check | immediatefeedback, immediate results without self-assessment | Unlimited (0) | Highest (QUIZ_GRADEHIGHEST) | One question/page, free navigation | None | Hide correct answers; show overall feedback | 80% |
| progress-check, default | deferredcbm, later results with self-assessment | Unlimited (0) | Highest (QUIZ_GRADEHIGHEST) | All questions on one page, free navigation | At least 5 minutes | Hide correct answers; overall feedback for learning plans | 80% |
| final-test | deferredfeedback, later results without self-assessment | At most 2 | Average (QUIZ_GRADEAVERAGE) | All questions on one page, free navigation | At least 15 minutes | Hide correct answers; show overall feedback | 80% |

### Existing quiz passing threshold

1. Read `coursepilot_get_module_settings(cmid)`: grade is the maximum,
   gradepass is the gradebook's stored passing threshold.
2. Convert percentages to grade points: 80% of grade 10 is 8; of 25 is 20.
3. Call `coursepilot_update_quiz_settings(cmid, fields_json: '{"gradepass":8}')`
   without mode, preserving questions, arrangement and other settings.
   gradepass is a JSON number in [0, grade]; 0 disables it. Percentage
   text and numeric strings are invalid. When grade changes simultaneously,
   use the new maximum.
4. Read settings again and compare gradepass; the quiz catalog reports
   the same persisted points.

### Learner experience and monitoring tradeoffs

- mini-check: quick orientation/practice with immediate results, unlimited
  attempts and no delay.
- progress-check: later results, self-assessment and overall feedback for
  teachers and learners to decide the next learning step.
- final-test: concluding assessment with opportunities to improve, not a
  one-shot formal examination. Two attempts, delay and averaging focus
  on completion and improvement. Mode selection confirms its learner lock.
  Name the two attempts, delay and averaging in the plan; no extra attempt
  confirmation is needed.

### Mode selection

- Quick orientation or short practice: mini-check.
- Subtopic assessment with learning planning: progress-check.
- End-of-section assessment allowing improvement: final-test.

## Named course question banks

Before accessing categories or questions, establish a named course bank
through `coursepilot_ensure_question_bank`. Propose a teacher-readable name
based on course, topic or subject, e.g. Biology 9a — Immune system or
Chemistry — Acids and bases. No technical Coursepilot prefix.

This is a planning decision: preview name and structure and allow changes
before Moodle writes. Default structure:

- Bank for the course or subject project.
- Categories per subtopic.
- Numbered content sections beneath them where needed.

Name categories after the numbered course content section, e.g.
7.2 Materials and their properties. This makes questions discoverable by
subtopic/section; see CONTEXT.md course bank and numbered content section.

`coursepilot_ensure_question_category` reuses an identical name under the
same parent, returning its id with created=false rather than duplicating.
parent is required, e.g. the bank's topcategoryid for direct children.

### Nondestructive cleanup

Move misplaced categories through `coursepilot_update_question_category`
into the correct named bank or target category; optionally rename them.
Preserve questions and descendants. Never use deletion for this cleanup.

Preview source, target, affected main category/known descendants and new
name or parent before explicit approval. V1 includes no deletion of
questions or categories.

## Plan quizzes and questions (#20)

Use the same implementation-plan workflow as other activities: build,
show overview and wait for approval.

1. Establish the named bank as a planning decision before the first quiz.
   Show name/structure for confirmation or revision. After approval,
   resolve it through coursepilot_ensure_question_bank and use returned
   questionbankid for categories/questions.
2. Unless planned otherwise, use QUIZ_LERNCHECK_MODE_DEFAULT
   (mode:progress-check) and QUIZ_PASS_COMPLETION_DEFAULT
   (completion=2, completionpassgrade=1, pass-grade completion). Another
   mode or completion configuration is a justified plan deviation.
3. Plan each question using coursepilot_create_mc_question's shape:
   questiontext, selectionmode, answers with answer/fraction/feedback,
   generalfeedback, plus a source activity already in the plan from which
   learners can answer it. Store a readable question preview in plan.md.
4. Mark missing/unresolvable source activities as material gaps in both
   plan.md and the overview. Create no gap questions and make no
   coursepilot_create_mc_question/coursepilot_add_questions_to_quiz calls
   for them. Show gaps before approval; the teacher chooses approved
   material additions (#19) or revised questions.
5. After approval, create the quiz with mode/grade, set completion and
   restrictions, create nongap questions, then attach all in one
   `coursepilot_add_questions_to_quiz` call (#13). Set activity.categoryid
   when the quiz has questions; it belongs to the previously approved bank.
