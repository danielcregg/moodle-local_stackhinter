# STACK AI Hinter (local_stackhinter)

[![Moodle Plugin CI](https://github.com/danielcregg/moodle-local_stackhinter/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/danielcregg/moodle-local_stackhinter/actions/workflows/moodle-ci.yml)
![Moodle 4.5](https://img.shields.io/badge/Moodle-4.5%20LTS-orange)
![License GPL v3](https://img.shields.io/badge/license-GPLv3-blue)

A Moodle **local plugin** that adds patient, escalating **Socratic hints** to
[STACK](https://stack-assessment.org/) quiz-attempt pages. When a student is stuck, they press
**💡 Hint** and get a short nudge about *their* specific mistake — **never** the final answer. The AI
provider's API key lives **server-side**; the browser only ever calls this plugin's own endpoint.

```mermaid
sequenceDiagram
    participant S as Student
    participant M as Moodle quiz (STACK)
    participant P as local_stackhinter
    participant AI as AI provider (server-side)
    S->>M: submit answer
    M-->>S: STACK grades (right/wrong + feedback)
    S->>P: press 💡 Hint
    P->>P: require_login · sesskey · mod/quiz:attempt · abuse cap
    P->>AI: Socratic prompt (API key stays on the server)
    AI-->>P: escalating hint (never the answer)
    P->>P: log to local_stackhinter_hints
    P-->>S: hint
```

## Requirements

- **Moodle 4.5 LTS** or later (developed and tested on 4.5; uses the Hooks API).
- The **STACK question type** (`qtype_stack`) — the hinter targets STACK questions and declares
  `qtype_stack` as a dependency.
- **No AI key is required by default.** With the default *Auto* backend the plugin runs a small model
  (gemma-2-2b) **on-device in the student's browser** (WebGPU) — no key, no external provider, and no
  answer data leaves the browser (see [On-device AI](#on-device-ai-in-the-browser)). A recent
  WebGPU-capable browser (Chrome/Edge, or Safari 18+) is required. To run hints server-side instead (or to
  cover browsers without WebGPU), configure Moodle's built-in **core AI** (a provider under *Site
  administration → AI* — no separate key needed) or this plugin's own API key for one external provider
  (OpenAI, Anthropic Claude, Google Gemini, AWS Bedrock, OpenRouter, Groq, Mistral, Cerebras, or an
  OpenAI-compatible gateway — AWS Bedrock additionally takes an AWS region).

## Install

### From the Moodle Plugins directory (recommended once published)
Site administration → Plugins → Install plugins → search for *STACK AI Hinter*.

### Manually
Copy this directory to `<moodleroot>/local/stackhinter` (the folder **must** be named `stackhinter`), then
visit *Site administration → Notifications*, or run `php admin/cli/upgrade.php --non-interactive`.

## Quick start

The plugin **works out of the box with no API key**: install it, tick **Enable STACK AI Hinter**, and turn
hints on for a quiz in its *Settings → STACK AI Hinter*. With the default **Auto** backend, hints run
**on-device in each student's browser** — the browser downloads gemma-2-2b (~1.6 GB) once on the first hint
and caches it, a recent **WebGPU** browser (Chrome/Edge, or Safari 18+) is required, and nothing about the
answer leaves the browser.

To run hints **server-side instead** (e.g. to cover older browsers or avoid the per-student download),
configure one of these — the **Auto** backend then uses it automatically:

**Option A: a free API key (fastest for a single site).**
1. Create a free key at [openrouter.ai/keys](https://openrouter.ai/keys), or use Google's [Gemini free tier](https://aistudio.google.com/apikey).
2. In *Site administration → Plugins → Local plugins → STACK AI Hinter*, set **AI provider** to **OpenRouter**, paste the key into **AI API key**, and set **Model** to a current model id ending in `:free` from the [free models list](https://openrouter.ai/models?max_price=0) (for example `meta-llama/llama-3.3-70b-instruct:free`). With Gemini instead, use provider **Google Gemini** and model `gemini-2.5-flash`.
3. Tick **Enable STACK AI Hinter**, then turn hints on for a quiz in its *Settings → STACK AI Hinter*.

**Option B: reuse Moodle's built-in AI (no separate key).** If an administrator has configured an AI provider under *Site administration → AI*, the **Auto** backend uses Moodle's core AI automatically, inheriting its AI policy and logging. This is the cleanest path for an institution that already runs AI centrally.

> With the server-side backends (Options A and B), each hint sends only the question text, the student's current answer, the grader feedback and a one-word CAS diagnosis; the model answer is never sent. Pick a provider whose data-handling terms suit your institution. (The default on-device backend sends nothing to any AI provider.)

## Configure

*Site administration → Plugins → Local plugins → STACK AI Hinter*. The plugin is **disabled by default**
and does nothing until you:

| Setting | Description |
|---|---|
| **Enable STACK AI Hinter** | Site master switch (off by default). When on, teachers turn hints on **per quiz** (off by default) in each quiz's settings — so they never appear on a quiz nobody opted in, including exams. |
| **AI backend** | *Auto* (default), Moodle's built-in core AI, this plugin's own provider/key, or **On-device AI**. *Auto* needs no configuration: it uses core AI if the site has it, else an own provider if a key is set, else **on-device** (gemma-2-2b, in the student's browser via WebGPU — no key, no external provider). The on-device model is fixed to gemma-2-2b (the only small model that never leaked the answer in testing) and is not configurable. |
| **AI provider** | Which external service generates hints (used by the own-provider backend). |
| **Model** | The model id, e.g. `gpt-4o-mini`, `gemini-2.5-flash`, `claude-3-5-haiku`. |
| **AI API key** | Stored server-side, never sent to the browser. |

**Per quiz** (in each quiz's *Settings → STACK AI Hinter*): a teacher enables hints on that quiz (off by
default) and sets the **max hints per question** for it (a separate hard server cap also prevents abuse).

## How it works

- A footer hook loads the `local_stackhinter/hinter` AMD module **only** on quiz-attempt pages of quizzes a
  teacher has enabled hints on (off by default), and the module attaches to STACK questions. The
  per-quiz opt-in is also enforced server-side on every endpoint.
- The module adds a hint button to each STACK question, reads the student's current answer + grader
  feedback from the DOM, and posts them to `ajax.php`.
- `ajax.php` re-checks `mod/quiz:attempt`, enforces an abuse cap, calls the AI **server-side**, logs
  the hint, and returns it. The Socratic system prompt forbids revealing the answer.

### CAS-grounded hints (the oracle, not the LLM, does the maths)

LLMs are unreliable at symbolic maths, so the hinter never asks the AI to judge correctness. Instead,
for a STACK question it asks **Moodle's own STACK / Maxima** to classify how the student's current
answer relates to a correct one, and gives the AI only that qualitative class:

- **equivalent** — algebraically correct but in the wrong *form* (e.g. not expanded/factored);
- **constant** — off by a constant term;
- **structural** — a term involving the variable is wrong, missing or extra.

Only the class is sent to the AI. The model answer and the exact difference are computed server-side
and never leave it, so the hint stays accurate **and** cannot leak the answer. If grounding is not
available (non-STACK or multi-input question, an invalid answer, or any CAS error) the hinter falls
back to hinting from the question text and grader feedback alone. The student value enters the CAS only
through STACK's own validated-input path, and the question usage is verified to belong to the student's
own attempt first.

## On-device AI (in the browser)

The hinter can run a small language model **entirely in the student's browser** using WebGPU (via
[WebLLM](https://github.com/mlc-ai/web-llm)). This is the **default** with the *Auto* backend when no
server-side AI is configured, so the plugin works with no key; you can also select **On-device AI**
explicitly. In this mode:

- **No API key and no external AI provider are involved.** The question text, the student's answer, the
  grader feedback and the one-word CAS diagnosis are processed locally in the browser and are **never**
  sent to any external AI service. The only network request the model makes is a **one-time download** of
  the model weights from a public CDN (cached by the browser afterwards); that request carries no personal
  data.
- **The model is gemma-2-2b** (`gemma-2-2b-it-q4f16_1-MLC`, ~1.6 GB, downloaded once on the first hint and
  cached). It is fixed and not configurable: in evaluation it was the only small model that **never leaked
  the answer**, which matters because the server-side leak-guard cannot run in the browser. Larger and
  "thinking"/reasoning models (Llama-3.2-3B, Gemma 4, Qwen3, …) leaked the answer and are deliberately not
  offered.
- **Requirements:** a recent, **WebGPU-capable** browser (current Chrome, Edge, or equivalent) with enough
  memory for the model. If a student's browser has no WebGPU, the hint button reports that on-device hints
  are unavailable and no hint is consumed; an administrator can instead choose a server-side backend.
- Hints generated on-device are logged to `local_stackhinter_hints` exactly like server-side hints (the
  browser posts the finished hint back to the plugin), and the same per-quiz opt-in and hint caps apply.

## Privacy

This plugin **stores** a per-user hint log (`local_stackhinter_hints`) and, for the server-side backends,
**discloses** the question text, the student's answer, the grader feedback, and (for STACK questions) a
short qualitative diagnosis of the answer to the configured AI backend in order to generate a hint. When
the backend is Moodle's built-in core AI, the request is handled by Moodle's core AI subsystem, which
governs that disclosure. When the backend is **on-device AI**, the model runs in the student's browser and
**nothing is sent to any external AI provider** — the question, answer, feedback and diagnosis are
processed locally, and the only external request is the one-time model download from a public CDN, which
carries no personal data. The model answer and exact CAS values are never sent in any mode. All of this is
declared via the Moodle Privacy API (`classes/privacy/`), including full export and deletion support.
Choose a backend and provider whose data-handling terms suit your institution.

## Security

- Disabled by default; no external call until fully configured.
- Server-side key only; capability-gated (`mod/quiz:attempt`) with sesskey; per-user/per-quiz hint cap.
- Per-quiz opt-in is enforced server-side on every endpoint, so hints cannot be used on a quiz that
  did not enable them.

## For reviewers / maintainers

- To exercise hints, set a provider + model + key, enable hints on a quiz, and attempt that STACK quiz.
- `amd/build/hinter.min.js` is generated from `amd/src/hinter.js` by Moodle's `grunt amd`. After editing
  the source, rebuild with `grunt amd` from a Moodle checkout so the committed build stays canonical
  (this is what `moodle-plugin-ci grunt` verifies).

## License

[GNU GPL v3 or later](LICENSE) — the same license as Moodle.
