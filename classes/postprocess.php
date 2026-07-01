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
 * Post-processing of AI hint text: cleaning and the answer-leak guard.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Cleans and guards the hint text an AI model returns before it is stored or shown to a student.
 */
class postprocess {
    /**
     * Clean artifacts smaller models emit despite the plain-text rule: reasoning/think blocks, LaTeX and
     * markdown delimiters, leading list markers, echoed prompt labels and chatty preambles; then keep the
     * hint brief (at most three sentences) and capitalise its first word.
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
        $interjection = '/^\s*(sure|okay|ok|of course|certainly|absolutely|great question|good question)' .
            '\b[\s,!:.\-]+/i';
        $text = preg_replace($interjection, '', $text);
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
}
