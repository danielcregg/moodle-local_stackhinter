# Changelog

All notable changes to **local_stackhinter** are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

> **Note:** this plugin was developed under the working name `local_aitutor` ("AI Tutor"). It was
> renamed to **`local_stackhinter` ("STACK AI Hinter")** before its first Moodle Plugins directory
> release, to make its STACK-specific purpose clear and findable. Earlier entries below describe that
> same codebase.

## [Unreleased]

### Added
- **On-device (in-browser) AI backend.** A new "On-device" AI backend runs a small model
  (`gemma-2-2b` by default, `Llama-3.2-3B` as a backup) entirely in the student's browser via
  WebLLM/WebGPU — no API key, and no answer data leaves the browser. The server returns only the
  bounded, CAS-grounded prompt; the generated hint is logged back via a `logondevice` action.
- **Few-shot prompting, tight decoding, and an output pipeline** (`classes/postprocess.php`) that
  sanitises every backend's hint and applies a server-side answer-leak guard.

### Fixed
- **On-device model now actually loads in the browser.** Moodle's AMD build (Babel +
  system-import-transformer) was rewriting the native `import()` of the WebLLM ES module into a RequireJS
  `require()`, which cannot load an ES module from a CDN — so the on-device backend failed instantly on
  every browser ("attempts to generate then quickly fails"). The module is now loaded via an injected
  `<script type="module">`, whose `import()` is plain script text the build leaves untouched, so it runs
  natively. (A standalone WebGPU PoC using a raw ESM import always worked, which isolated the cause.)
- **Teachers can now test the hint when previewing their own quiz.** The hint endpoint required
  `mod/quiz:attempt`, which teachers do not hold (they preview with `mod/quiz:preview`), so clicking
  Hint in a quiz preview failed silently and — for the on-device backend — never triggered the
  in-browser model download. The endpoint now accepts either capability; hint requests are still bound
  to the requester's own attempt, so this grants no access to other users' attempts.

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
- **Quick-start guidance for a free setup.** The own-provider settings now describe a roughly 2-minute,
  no-cost path (a free OpenRouter model ending in `:free`, or Google Gemini's free tier) with direct links,
  and the README has a matching "Quick start" section.
- **On-device AI backend (no key, no external provider).** A new **On-device AI** choice for the AI backend
  runs a small language model entirely in the student's browser via WebGPU (WebLLM), so hints are generated
  locally with no API key and nothing sent to any external AI provider. A new **On-device model** setting
  picks the model: **Gemma 2 2B** (`gemma-2-2b-it-q4f16_1-MLC`, the recommended default: smallest and
  fastest) or **Llama 3.2 3B** (`Llama-3.2-3B-Instruct-q4f16_1-MLC`, a higher-quality alternative that
  downloads more data). The browser downloads the chosen model once from a public CDN and caches it; a
  WebGPU-capable browser is required, and a browser without WebGPU is told on-device hints are unavailable
  (no hint is consumed). Hints generated on-device are logged like server-side hints. "Thinking"/reasoning
  models are not offered because they tend to reveal the answer, and the own-provider model help text now
  warns against reasoning models for the same reason.

### Changed
- **Hint output is now sanitised** server-side before it is stored or shown: reasoning/`<think>` blocks
  (e.g. from Qwen3-family models), LaTeX delimiters and commands (`\(`, `\frac`, `\sin`, ...), markdown
  emphasis, echoed prompt labels and leading list markers are stripped.
- **Better hints from weaker models (pre/post-processing).** The system prompt now includes two worked
  examples (few-shot); generation uses tighter decoding (lower temperature, a token cap and a stop
  sequence). The sanitiser additionally strips chatty preambles, caps a hint at three sentences and
  capitalises the first word. A **server-side answer-leak filter** checks each generated hint against the
  teacher answer (computed by Maxima, never sent to the model) and substitutes a safe, diagnosis-based hint
  if the model stated the answer. A reasoning ("thinking") model that returns only its reasoning is now
  detected and reported rather than failing silently. The system prompt now also tells the model to address the student directly and to
  output only the hint. Together these remove the formatting artefacts and prompt-parroting that smaller or
  self-hosted models can otherwise produce. (Backed by an on-device model evaluation across the latest
  small web-runnable models; gemma-2-2b-class models give the best small-model hints.)
- The default **AI provider is now OpenRouter** (which offers free models) rather than OpenAI; existing
  sites keep whatever provider they already configured.
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
