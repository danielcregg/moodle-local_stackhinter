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
 * Non-leaking, server-side task guidance to steer a small model's hint.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Classifies the maths task from the question text and supplies the extra guidance that lets a small
 * model spend its capacity on wording rather than working out the maths: a task description, the method
 * to steer the student toward, and one matched few-shot example. Everything here is derived only from the
 * QUESTION (never the answer) and states no value, so it cannot leak the answer. Evaluation on the hint
 * pipeline (gemma-2-2b, judged by qwen2.5:14b) showed this "enriched" guidance beats the generic prompt
 * (+9% quality) at 0% answer-leak; over-specifying it (adding a per-attempt goal) was worse, so we stop
 * here. If the task cannot be confidently identified, classify() returns null and the caller falls back
 * to the plain grounding, so guidance is never worse than before.
 */
class task_guide {
    /** @var array Per-task guidance: a human description, the method to steer toward, and a matched example. */
    const GUIDE = [
        'differentiate' => [
            'desc' => 'differentiate the function (find its derivative)',
            'method' => 'the power rule: multiply by the current exponent and reduce the exponent by one',
            'example' => "\nExample of the right style (invent your own wording; never copy this): "
                . "differentiating x^5, a student wrote x^4. Good hint: \"The power rule brings the exponent "
                . "down as a coefficient and lowers it by one; what does that do to x^5?\"",
        ],
        'difftrig' => [
            'desc' => 'differentiate a trigonometric function',
            'method' => 'the standard derivatives of sine and cosine',
            'example' => "\nExample of the right style (invent your own wording; never copy this): "
                . "differentiating cos(x), a student kept the sign. Good hint: \"Recall the standard "
                . "derivatives of sine and cosine; which one picks up a minus sign?\"",
        ],
        'integrate' => [
            'desc' => 'find the indefinite integral (the antiderivative)',
            'method' => 'reverse the power rule, and remember to add a constant of integration',
            'example' => "\nExample of the right style (invent your own wording; never copy this): "
                . "integrating 3x^2, a student wrote x^3 and nothing else. Good hint: \"Differentiating your "
                . "answer returns the integrand, but what term differentiates to zero and so could be missing?\"",
        ],
        'expand' => [
            'desc' => 'expand by multiplying out the brackets',
            'method' => 'the distributive law: multiply each term in the first bracket by each term in the '
                . 'second, then collect like terms',
            'example' => "\nExample of the right style (invent your own wording; never copy this): asked to "
                . "expand (x+1)(x+4), a student left it factored. Good hint: \"That is the right expression, "
                . "but the question wants it multiplied out; what do you get when each term multiplies each term?\"",
        ],
        'factor' => [
            'desc' => 'factorise the expression',
            'method' => 'find two numbers that multiply to the constant term and add to the coefficient of x',
            'example' => "\nExample of the right style (invent your own wording; never copy this): factorising "
                . "x^2+7x+12, a student left it unchanged. Good hint: \"Which two numbers multiply to the "
                . "constant term and add to the coefficient of x?\"",
        ],
        'solve' => [
            'desc' => 'solve the equation for the variable',
            'method' => 'factor the quadratic (or use the quadratic formula), then set each factor to zero, '
                . 'and expect two solutions',
            'example' => "\nExample of the right style (invent your own wording; never copy this): solving "
                . "x^2-3x+2=0, a student gave only one value. Good hint: \"A quadratic usually has two "
                . "solutions; once you factor it, what value makes each factor zero?\"",
        ],
        'simplify' => [
            'desc' => 'simplify the expression to its simplest form',
            'method' => 'factor the numerator and cancel common factors with the denominator',
            'example' => "\nExample of the right style (invent your own wording; never copy this): simplifying "
                . "(3x^2+6x)/(3x), a student left it unchanged. Good hint: \"What common factor can you take out "
                . "of the top, and what then cancels with the bottom?\"",
        ],
    ];

    /** @var string Two generic examples, used when the task cannot be classified. */
    const GENERIC_FEWSHOT =
        "\nTwo examples of the right style (invent your own wording for the real question, never copy these):\n"
        . "- Differentiating x^3, a student wrote x^2. Good hint: \"The power rule brings the exponent down as "
        . "a coefficient and lowers it by one; what does that do to x^3?\"\n"
        . "- Asked to expand (x+1)(x+2), a student left it factored. Good hint: \"That is the right expression, "
        . "but the question wants it multiplied out; what do you get when each term multiplies each term?\"";

    /**
     * Classify the maths task from the question text.
     *
     * @param string $question The question text.
     * @return array|null ['task' => string, 'desc' => string, 'method' => string], or null if not confident.
     */
    public static function classify(string $question): ?array {
        $q = ' ' . \core_text::strtolower($question) . ' ';
        $task = null;
        if (strpos($q, 'differentiat') !== false || strpos($q, 'derivative') !== false) {
            // Word-boundary match so "using" does not read as "sin", etc.
            $trig = preg_match('/\b(sin|cos|tan|sec|csc|cot)\b/', $q) === 1;
            $task = $trig ? 'difftrig' : 'differentiate';
        } else if (strpos($q, 'integra') !== false || strpos($q, 'antiderivative') !== false) {
            // Match the noun "integral" as well as integrate/integration/integrand.
            $task = 'integrate';
        } else if (strpos($q, 'expand') !== false || strpos($q, 'multiply out') !== false) {
            $task = 'expand';
        } else if (strpos($q, 'factor') !== false) {
            $task = 'factor';
        } else if (strpos($q, 'solve') !== false) {
            $task = 'solve';
        } else if (strpos($q, 'simplif') !== false) {
            $task = 'simplify';
        }
        if ($task === null || !isset(self::GUIDE[$task])) {
            return null;
        }
        return ['task' => $task, 'desc' => self::GUIDE[$task]['desc'], 'method' => self::GUIDE[$task]['method']];
    }

    /**
     * The few-shot block for the system prompt: a task-matched example when classified, else two generic ones.
     *
     * @param array|null $guide The result of classify(), or null.
     * @return string The few-shot block to append to the system prompt.
     */
    public static function fewshot(?array $guide): string {
        if ($guide !== null && isset(self::GUIDE[$guide['task']]['example'])) {
            return self::GUIDE[$guide['task']]['example'];
        }
        return self::GENERIC_FEWSHOT;
    }
}
