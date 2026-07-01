<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Server-side AI client for STACK AI Hinter plugin.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Holds the provider configuration (including the API key) and calls the configured external AI to
 * produce ONE escalating Socratic hint. The key never reaches the browser.
 */
class ai_client {
    /** @var array OpenAI-compatible / gemini / anthropic endpoints, keyed by provider id. */
    const PROVIDERS = [
        'openai' => ['kind' => 'openai', 'endpoint' => 'https://api.openai.com/v1/chat/completions'],
        'groq' => ['kind' => 'openai', 'endpoint' => 'https://api.groq.com/openai/v1/chat/completions'],
        'mistral' => ['kind' => 'openai', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions'],
        'cerebras' => ['kind' => 'openai', 'endpoint' => 'https://api.cerebras.ai/v1/chat/completions'],
        'zenmux' => ['kind' => 'openai', 'endpoint' => 'https://zenmux.ai/api/v1/chat/completions'],
        'openrouter' => ['kind' => 'openai', 'endpoint' => 'https://openrouter.ai/api/v1/chat/completions'],
        // AWS Bedrock now exposes an OpenAI-compatible API; the key is an Amazon Bedrock API key, and %s
        // is the AWS region (from the region setting), substituted in before the request.
        'bedrock' => ['kind' => 'openai', 'endpoint' => 'https://bedrock-runtime.%s.amazonaws.com/openai/v1/chat/completions'],
        'claude' => ['kind' => 'anthropic', 'endpoint' => 'https://api.anthropic.com/v1/messages'],
        'gemini' => ['kind' => 'gemini', 'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/'],
    ];

    /** @var string The Socratic system prompt that instructs the AI to give one escalating hint. */
    const SYSTEM =
        "You are a patient, Socratic mathematics coach embedded in a STACK quiz.\n" .
        "Rules:\n" .
        "- NEVER state the final answer or give a full worked solution. Lead the student to it.\n" .
        "- Reply with exactly ONE hint, 1-3 sentences, about the student's specific mistake.\n" .
        "- Address the student directly as \"you\"; never talk about \"the student\" in the third person.\n" .
        "- Output ONLY the hint itself: no labels or headings, do not restate the question, answer, " .
        "feedback, diagnosis or attempt number, and do not show any reasoning or thinking.\n" .
        "- Escalate by attempt: 1 = a gentle conceptual nudge; 2 = point to the specific step or " .
        "error; 3+ = name the method/rule to apply (but still NOT the final value).\n" .
        "- Ground the hint in the student's actual answer and the grader feedback.\n" .
        "- You may also be given an authoritative CAS DIAGNOSIS of the student's answer (whether it is " .
        "equivalent but in the wrong form, off by a constant, or has a wrong term). Trust it over your " .
        "own algebra and use it to target your hint; do not quote it verbatim.\n" .
        "- Be encouraging and concise. Plain text only: no markdown, asterisks, or LaTeX/backslash delimiters.\n" .
        "Two examples of the right style (invent your own wording for the real question, never copy these):\n" .
        "- Differentiating x^3, a student wrote x^2. Good hint: \"The power rule brings the exponent down as " .
        "a coefficient and lowers it by one; what does that do to x^3?\"\n" .
        "- Asked to expand (x+1)(x+2), a student left it factored. Good hint: \"That is the right expression, " .
        "but the question wants it multiplied out; what do you get when each term multiplies each term?\"";

    /**
     * Resolve which AI backend handles a request: 'core' (Moodle's AI subsystem) or 'own' (this
     * plugin's configured provider/key).
     *
     * @param \context|null $context The request context (core needs one; null forces 'own' in auto).
     * @return string 'core' or 'own'.
     */
    public static function resolve_backend(?\context $context): string {
        $backend = (string) get_config('local_stackhinter', 'aibackend');
        if ($backend === 'core') {
            return 'core';
        }
        if ($backend === 'own') {
            return 'own';
        }
        if ($backend === 'ondevice') {
            return 'ondevice';
        }
        // Auto (default): prefer core only when it is actually available, else this plugin's provider.
        return ($context !== null && core_ai::available()) ? 'core' : 'own';
    }

    /**
     * A short label of the backend that would handle a request, for logging.
     *
     * @param \context|null $context The request context.
     * @return string 'core_ai' or 'own:<provider>'.
     */
    public static function backend_label(?\context $context): string {
        if (self::resolve_backend($context) === 'core') {
            return 'core_ai';
        }
        return 'own:' . (string) get_config('local_stackhinter', 'provider');
    }

    /**
     * Generate a single Socratic hint.
     *
     * @param string $question The question text the student is working on.
     * @param string $answer The student's current answer.
     * @param string $feedback The grader feedback for that answer.
     * @param int $attempt The hint attempt number (drives escalation).
     * @param array $grounding Optional CAS-verified diagnosis (['class' => ...]) from stack_grounding.
     * @param \context|null $context The request context (for the core AI backend).
     * @param int $userid The requesting user id (for the core AI policy check).
     * @return string The hint text.
     * @throws \moodle_exception If not configured, the AI policy is unaccepted (aipolicyrequired), or the call fails.
     */
    public static function hint(
        string $question,
        string $answer,
        string $feedback,
        int $attempt,
        array $grounding = [],
        ?\context $context = null,
        int $userid = 0
    ): string {
        $user = self::build_user($question, $answer, $feedback, $attempt, $grounding);

        // Route through Moodle's core AI when selected/available.
        if (self::resolve_backend($context) === 'core') {
            if ($userid <= 0) {
                $userid = (int) ($GLOBALS['USER']->id ?? 0);
            }
            // Respect the AI User Policy — never bypass it by quietly using the own-provider path.
            if (!core_ai::policy_accepted($userid)) {
                throw new \moodle_exception('aipolicyrequired', 'local_stackhinter');
            }
            $text = core_ai::generate_text($context, $userid, self::SYSTEM . "\n\n" . $user);
            // No silent failover to a different provider: the admin chose core, so surface the failure.
            if ($text === null || trim($text) === '') {
                throw new \moodle_exception('aifailed', 'local_stackhinter', '', 'core AI provider failed');
            }
            return self::guard(self::nonempty(self::sanitize($text)), $grounding);
        }

        // This plugin's own provider path.
        $providerid = (string) get_config('local_stackhinter', 'provider');
        $model = (string) get_config('local_stackhinter', 'model');
        $key = (string) get_config('local_stackhinter', 'apikey');
        if ($providerid === '' || !isset(self::PROVIDERS[$providerid])) {
            throw new \moodle_exception('noprovider', 'local_stackhinter');
        }
        if ($model === '') {
            throw new \moodle_exception('nomodel', 'local_stackhinter');
        }
        if ($key === '') {
            throw new \moodle_exception('nokey', 'local_stackhinter');
        }
        $p = self::PROVIDERS[$providerid];
        // AWS Bedrock's endpoint is region-specific; fill %s from the region setting. Other providers
        // have no placeholder, so this leaves their endpoint unchanged.
        $endpoint = str_replace('%s', self::region(), $p['endpoint']);

        switch ($p['kind']) {
            case 'openai':
                $text = self::call_openai($endpoint, $key, $model, self::SYSTEM, $user);
                break;
            case 'gemini':
                $text = self::call_gemini($endpoint, $key, $model, self::SYSTEM, $user);
                break;
            case 'anthropic':
                $text = self::call_anthropic($endpoint, $key, $model, self::SYSTEM, $user);
                break;
            default:
                throw new \moodle_exception('noprovider', 'local_stackhinter');
        }
        return self::guard(self::nonempty(self::sanitize($text)), $grounding);
    }

    /**
     * The configured AWS region for the Bedrock provider (defaults to us-east-1).
     *
     * @return string The AWS region, e.g. 'us-east-1'.
     */
    private static function region(): string {
        $region = trim((string) get_config('local_stackhinter', 'region'));
        return $region !== '' ? $region : 'us-east-1';
    }

    /**
     * Build the optional CAS-diagnosis block for the prompt (empty string when no grounding available).
     *
     * Only a qualitative class is included — never the model answer or the exact difference — so the
     * hint cannot leak the answer even if the model ignores instructions.
     *
     * @param array $grounding ['class' => 'equivalent'|'constant'|'structural'] from stack_grounding.
     * @return string The prompt fragment, or '' for feedback-only hinting.
     */
    private static function grounding_block(array $grounding): string {
        $facts = [
            'equivalent' => "the student's answer is algebraically EQUIVALENT to a correct answer but is "
                . "in the wrong FORM for what the question asks (e.g. not expanded, factored or simplified "
                . "as required) — nudge them toward the required form",
            'constant' => "the student's answer differs from a correct answer by only a CONSTANT (a term "
                . "with no variable) — they have likely added or dropped a constant term; do not say which",
            'structural' => "the student's answer differs from a correct answer by a term that INVOLVES "
                . "THE VARIABLE — a term is wrong, missing or extra; point them to re-check their working "
                . "without naming the term",
        ];
        $class = $grounding['class'] ?? '';
        if (!isset($facts[$class])) {
            return '';
        }
        return "CAS DIAGNOSIS (computed by the question's own Maxima — authoritative): "
            . $facts[$class] . ".\n";
    }

    /**
     * Assemble the user-turn prompt (question, answer, feedback, the grounded diagnosis, attempt).
     *
     * Only the diagnosis CLASS is included from $grounding; the model answer is never put in the prompt.
     *
     * @param string $question The question text.
     * @param string $answer The student's current answer.
     * @param string $feedback The grader feedback.
     * @param int $attempt The hint attempt number.
     * @param array $grounding Optional CAS diagnosis (['class' => ...]).
     * @return string The user-turn prompt.
     */
    private static function build_user(
        string $question,
        string $answer,
        string $feedback,
        int $attempt,
        array $grounding
    ): string {
        return "QUESTION: {$question}\n"
            . "STUDENT'S ANSWER: " . ($answer !== '' ? $answer : '(blank)') . "\n"
            . "GRADER FEEDBACK: " . ($feedback !== '' ? $feedback : '(none)') . "\n"
            . self::grounding_block($grounding)
            . "ATTEMPT NUMBER: {$attempt}\n"
            . "Give one Socratic hint appropriate to this attempt number.";
    }

    /**
     * The system + user prompt for a hint, for backends that run the model elsewhere (the on-device
     * browser backend). The model answer is never included — only the bounded diagnosis class.
     *
     * @param string $question The question text.
     * @param string $answer The student's current answer.
     * @param string $feedback The grader feedback.
     * @param int $attempt The hint attempt number.
     * @param array $grounding Optional CAS diagnosis (['class' => ...]).
     * @return array ['system' => string, 'user' => string].
     */
    public static function build_messages(
        string $question,
        string $answer,
        string $feedback,
        int $attempt,
        array $grounding = []
    ): array {
        return [
            'system' => self::SYSTEM,
            'user' => self::build_user($question, $answer, $feedback, $attempt, $grounding),
        ];
    }

    /**
     * The configured on-device (in-browser) model id, defaulting to gemma-2-2b.
     *
     * @return string A WebLLM model id.
     */
    public static function ondevice_model(): string {
        $m = trim((string) get_config('local_stackhinter', 'ondevicemodel'));
        return $m !== '' ? $m : 'gemma-2-2b-it-q4f16_1-MLC';
    }

    /**
     * If a generated hint contains the (server-side-only) model answer, replace it with a safe,
     * diagnosis-based fallback. The model answer is computed by Maxima and never sent to the model, but a
     * weak model can still reconstruct and state it; this is the last line of defence against a leak.
     *
     * @param string $hint The cleaned hint.
     * @param array $grounding The grounding, which may carry the server-side 'answer'.
     * @return string The hint, or a safe fallback if it leaked the answer.
     */
    public static function guard(string $hint, array $grounding): string {
        $answer = (string) ($grounding['answer'] ?? '');
        if ($answer !== '' && self::leaks($hint, $answer)) {
            return self::safe_fallback($grounding);
        }
        return $hint;
    }

    /**
     * Whether a hint literally contains the model answer (ignoring spacing and asterisks). Very short
     * answers (under three characters, e.g. "x" or "0") are skipped to avoid false positives.
     *
     * @param string $hint The hint text.
     * @param string $answer The model answer.
     * @return bool True if the hint appears to state the answer.
     */
    private static function leaks(string $hint, string $answer): bool {
        $norm = static function (string $s): string {
            return strtolower((string) preg_replace('/[\s*]+/', '', $s));
        };
        $a = $norm($answer);
        return strlen($a) >= 3 && strpos($norm($hint), $a) !== false;
    }

    /**
     * A safe, non-revealing fallback hint built from the diagnosis class, used when a generated hint
     * leaked the answer.
     *
     * @param array $grounding The grounding (['class' => ...]).
     * @return string A generic but safe Socratic nudge.
     */
    private static function safe_fallback(array $grounding): string {
        $class = $grounding['class'] ?? '';
        $key = in_array($class, ['equivalent', 'constant', 'structural'], true)
            ? 'fallback_' . $class : 'fallback_generic';
        return get_string($key, 'local_stackhinter');
    }

    /**
     * Perform a JSON HTTP POST and return the decoded response.
     *
     * @param string $url The endpoint URL.
     * @param array $headers HTTP headers.
     * @param array $body The request body (JSON-encoded before sending).
     * @return array The decoded JSON response.
     * @throws \moodle_exception On transport error, non-2xx status, or invalid JSON.
     */
    private static function http(string $url, array $headers, array $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Container DNS returns IPv6-first but has no IPv6 egress; force IPv4.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // No curl_close(): it is deprecated in PHP 8+ (a no-op) — the handle is freed when $ch goes out of scope.
        if ($resp === false) {
            throw new \moodle_exception('aifailed', 'local_stackhinter', '', $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception('aifailed', 'local_stackhinter', '', $code . ': ' . substr((string) $resp, 0, 200));
        }
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('aifailed', 'local_stackhinter', '', 'invalid JSON');
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Call an OpenAI-compatible chat-completions endpoint.
     *
     * @param string $endpoint The endpoint URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The hint text.
     */
    private static function call_openai(string $endpoint, string $key, string $model, string $system, string $user): string {
        $j = self::http($endpoint, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], [
            'model' => $model, 'temperature' => 0.4, 'max_tokens' => 160, 'stop' => ["\n\n"],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]);
        $msg = $j['choices'][0]['message'] ?? [];
        $content = trim((string) ($msg['content'] ?? ''));
        // A "thinking" model with reasoning enabled returns its reasoning separately and an empty answer.
        if ($content === '' && (!empty($msg['reasoning']) || !empty($msg['reasoning_content']))) {
            throw new \moodle_exception('aireasoningmodel', 'local_stackhinter');
        }
        return self::nonempty($content);
    }

    /**
     * Call the Google Gemini generateContent endpoint.
     *
     * @param string $endpoint The endpoint base URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The hint text.
     */
    private static function call_gemini(string $endpoint, string $key, string $model, string $system, string $user): string {
        $url = $endpoint . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        $j = self::http($url, ['Content-Type: application/json'], [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 160, 'stopSequences' => ["\n\n"]],
        ]);
        $parts = $j['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            $text .= $part['text'] ?? '';
        }
        return self::nonempty(trim($text));
    }

    /**
     * Call the Anthropic Claude messages endpoint.
     *
     * @param string $endpoint The endpoint URL.
     * @param string $key The API key.
     * @param string $model The model id.
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @return string The hint text.
     */
    private static function call_anthropic(string $endpoint, string $key, string $model, string $system, string $user): string {
        $j = self::http(
            $endpoint,
            ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            [
                'model' => $model,
                'max_tokens' => 200,
                'temperature' => 0.4,
                'stop_sequences' => ["\n\n"],
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]
        );
        $blocks = $j['content'] ?? [];
        $text = '';
        foreach ($blocks as $b) {
            $text .= $b['text'] ?? '';
        }
        return self::nonempty(trim($text));
    }

    /**
     * Clean artifacts smaller models emit despite the plain-text rule: reasoning/think blocks, LaTeX and
     * markdown delimiters, leading list markers, and any echoed prompt labels. Defence in depth — the
     * system prompt already forbids these, but small or self-hosted models do not always comply, so the
     * hint is cleaned before it is stored or shown.
     *
     * @param string $text The raw hint text from the AI.
     * @return string The cleaned hint.
     */
    public static function sanitize(string $text): string {
        // Reasoning/thinking blocks (e.g. Qwen3-family) and any stray reasoning tags.
        $text = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text);
        $text = preg_replace('/<\/?(think|reasoning)\b[^>]*>/i', '', $text);
        // Convert a simple LaTeX fraction into a plain quotient, then remove the inline maths delimiters.
        $text = preg_replace('/\\\\frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/', '($1)/($2)', $text);
        $text = preg_replace('/\\\\[()\[\],]/', '', $text);
        // Remove the leading backslash from any remaining LaTeX command, keeping the readable word.
        $text = preg_replace('/\\\\([a-zA-Z]+)/', '$1', $text);
        // Markdown emphasis and inline maths markers.
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/\*([^*]+)\*/', '$1', $text);
        $text = str_replace('$', '', $text);
        // Echoed prompt labels at the start of a line, and a leading "1." list marker.
        $text = preg_replace(
            '/^\s*(QUESTION|STUDENT\'?S?(?:\s+ANSWER|\s+RESPONSE)?|GRADER FEEDBACK|CAS DIAGNOSIS|' .
            'ATTEMPT NUMBER|HINT|SOCRATIC HINT)\s*:.*$/im',
            '',
            $text
        );
        $text = preg_replace('/^\s*\d+\.\s+/', '', $text);
        // Strip a chatty interjection some models open with.
        $text = preg_replace('/^\s*(sure|okay|ok|of course|certainly|absolutely|great question|good question)\b[\s,!:.\-]+/i', '', $text);
        // Strip an explicit "Here is a hint:" lead-in (requires the word "hint" so it cannot eat real content).
        $text = preg_replace('/^\s*here(?:\'s| is)?(?: a| your)? hint\b[\s,!:.\-]*/i', '', $text);
        // Collapse whitespace left behind.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);
        $text = trim($text);
        // Keep it brief: small models ignore the "1-3 sentences" rule, so cap at the first three.
        $parts = preg_split('/(?<=[.!?])\s+/', $text);
        if (is_array($parts) && count($parts) > 3) {
            $text = implode(' ', array_slice($parts, 0, 3));
        }
        // Capitalise the first letter when it begins a word (never a lone maths variable such as x).
        if (strlen($text) >= 2 && ctype_lower($text[0]) && ctype_alpha($text[1])) {
            $text = ucfirst($text);
        }
        return $text;
    }

    /**
     * Guard against an empty AI response.
     *
     * @param string $text The candidate hint text.
     * @return string The non-empty hint text.
     * @throws \moodle_exception If the text is empty.
     */
    private static function nonempty(string $text): string {
        if ($text === '') {
            throw new \moodle_exception('aiempty', 'local_stackhinter');
        }
        return $text;
    }
}
