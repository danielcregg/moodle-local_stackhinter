# Changelog

All notable changes to **local_stackhinter** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

> **Note:** this plugin was developed under the working name `local_aitutor` ("AI Tutor"). It was
> renamed to **`local_stackhinter` ("STACK AI Hinter")** before its first Moodle Plugins directory
> release, to make its STACK-specific purpose clear and findable. Earlier entries below describe that
> same codebase.

## [1.2.0-beta] — 2026-06-30

Per-quiz control, plus a naming-consistency and build pass for the first directory release.

### Added
- **Per-quiz opt-in.** A new "Enable STACK AI Hinter on this quiz" checkbox (off by default) on the quiz
  settings form. The hint button appears only on quizzes a teacher has explicitly enabled, so hints never
  show on a quiz nobody opted in, including graded exams. The site-level setting remains the
  administrator's master switch, and the per-quiz option is shown to teachers only when the site has
  enabled the hinter.
- A `local_stackhinter_quiz` table storing the per-course-module flag, with an upgrade step.
- An event observer that removes a quiz's opt-in row and its hint log when the quiz is deleted, so no
  orphaned personal data remains once the module context is gone.
- A short note on the per-quiz settings making clear they apply only to STACK questions (the section
  shows on every quiz's settings form and is off by default).
- The per-quiz settings section is collapsed by default (it auto-expands on quizzes that already use the
  hinter), keeping the quiz form uncluttered.
- **AWS Bedrock and OpenRouter** AI providers. Bedrock uses its OpenAI-compatible Chat Completions API
  authenticated with an Amazon Bedrock API key, with an added "AWS region" setting (its endpoint is
  region-specific, and shown only when Bedrock is the chosen provider); every other provider still needs
  only a model and an API key.
- **Navigate hints.** The hint panel keeps every hint generated for a question and shows prev/next
  controls (with a "Hint n of N" counter), so a student can review earlier hints and move forward again.

### Changed
- **Max hints per question** moved from a site setting to the **per-quiz** settings form, so each quiz sets
  its own hint allowance (a 1–10 dropdown, default 3). An upgrade adds the column and removes the old site
  setting.
- **Naming consistency.** Renamed the AMD module and all remaining internal/user-facing wording from
  "tutor" to "hinter"/"hints", so naming matches the plugin's identity. The module is now
  `local_stackhinter/hinter` (was `…/tutor`); the in-progress and unavailable messages were reworded. No
  functional change.
- The committed `amd/build/hinter.min.js` is now generated canonically by Moodle's `grunt amd` (with a
  sourcemap), so it matches the `moodle-plugin-ci grunt` check exactly.
- The site "Enable STACK AI Hinter" description now explains the two-layer model (the site enables the
  hinter; teachers choose per quiz).
- The **Hint button now sits beside the STACK Check button** with the same size, shape and colour; the
  hint panel appears on its own line below the question's controls.
- Polished user-facing wording: plain punctuation throughout (no em dashes), a clearer and tidily
  laid-out per-quiz note, and a shorter "hint limit reached" message.

### Removed
- **DeepSeek** from the AI provider list.
- The optional "Practise next" RL banner and its `/recommend` endpoint, settings (RL teaching-policy URL
  and token) and privacy disclosure. The plugin is now focused purely on Socratic hints; an adaptive
  "what to practise next" recommendation belongs in a more actionable placement and is planned separately.

### Security
- The per-quiz opt-in is enforced **server-side** — both the JS injection and the hint endpoint — so a
  crafted request cannot use hints on a quiz that did not enable them.

## [1.1.0-beta] — 2026-06-28

Adds CAS-grounded hints for STACK questions.

### Added
- **CAS-grounded hints**: for STACK questions the plugin asks Moodle's own STACK / Maxima to classify
  how the student's current answer relates to a correct one — equivalent but in the wrong form, off by
  a constant, or structurally different — and gives only that qualitative class to the AI, so the hint
  is accurate without the model doing the (unreliable) algebra itself.
- The model answer and the exact difference are computed server-side and **never** sent to the AI or
  the browser, so a hint cannot leak the answer (even if a student games the input).
- Automatic fall-back to feedback-only hinting when grounding is unavailable (non-STACK or multi-input
  question, invalid answer, or any CAS error).

### Security
- The student value enters the CAS only through STACK's own validated-input mechanism (the same path
  STACK uses for grading); the question usage is verified to belong to the requesting user's attempt at
  this quiz before any grounding is computed.

## [1.0.0-beta] — 2026-06-23

First public release, prepared for submission to the Moodle Plugins directory.

### Added
- A Socratic AI **hint** button on STACK quiz-attempt pages: escalating hints that never reveal the
  answer, generated by a server-side AI provider (the API key never reaches the browser).
- Hints are logged to `local_stackhinter_hints`, with a complete **Privacy API** implementation
  (metadata, export, and delete for users/contexts).
- GPL v3 file headers, a `moodle-plugin-ci` workflow, and per-plugin documentation.

### Changed
- JavaScript moved from an injected inline `<script>` to a proper **AMD module**, loaded via
  `$PAGE->requires->js_call_amd`.
- All user-facing text moved to language strings; inline styles moved to `styles.css`.

### Security
- The plugin is **disabled by default**; no provider or key is assumed, and no external call is made
  until an administrator enables it and configures a provider, model and key.
