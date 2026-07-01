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
            'desc' => 'find an antiderivative of the function',
            'method' => 'reverse the power rule: raise the exponent by one and divide by that new exponent',
            'example' => "\nExample of the right style (invent your own wording; never copy this): "
                . "integrating x^2, a student wrote x^3 without dividing. Good hint: \"Reversing the power rule "
                . "raises the exponent by one, but what must you then divide by so differentiating gets you back?\"",
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
        'solvelinear' => [
            'desc' => 'solve the linear equation for x',
            'method' => 'isolate x: undo the additions and subtractions first, then divide both sides by the '
                . 'coefficient of x',
            'example' => "\nExample of the right style (invent your own wording; never copy this): solving "
                . "2*x+3=9, a student divided before subtracting. Good hint: \"Move the constant to the other "
                . "side first, then divide by the number in front of x; which step comes first?\"",
        ],
        'numerical' => [
            'desc' => 'evaluate the expression to a single number',
            'method' => 'substitute any given value and simplify step by step to one number, respecting the '
                . 'order of operations',
            'example' => "\nExample of the right style (invent your own wording; never copy this): evaluating "
                . "2+3*4, a student added first. Good hint: \"Which operation binds tighter here, and so must "
                . "happen before the addition?\"",
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
        if (strpos($q, 'integra') !== false || strpos($q, 'antiderivative') !== false) {
            // Integration is tested first: "antiderivative" contains the substring "derivative", so a
            // differentiate-first test would misread an antiderivative question as differentiation. Also
            // matches the noun "integral" and integrate/integration/integrand.
            $task = 'integrate';
        } else if (strpos($q, 'differentiat') !== false || strpos($q, 'derivative') !== false) {
            // Word-boundary match so "using" does not read as "sin", etc.
            $trig = preg_match('/\b(sin|cos|tan|sec|csc|cot)\b/', $q) === 1;
            $task = $trig ? 'difftrig' : 'differentiate';
        } else if (strpos($q, 'expand') !== false || strpos($q, 'multiply out') !== false) {
            $task = 'expand';
        } else if (strpos($q, 'factor') !== false) {
            $task = 'factor';
        } else if (strpos($q, 'solve') !== false) {
            // Linear vs quadratic: a quadratic has an x^2 term; a linear "solve for x" does not. The
            // quadratic guide ("factor, expect two solutions") would misdirect a linear equation.
            $quad = preg_match('/x\^2|x²|quadratic|squared/', $q) === 1;
            $task = $quad ? 'solve' : 'solvelinear';
        } else if (strpos($q, 'simplif') !== false) {
            $task = 'simplify';
        } else if (strpos($q, 'evaluate') !== false || strpos($q, 'as a decimal') !== false) {
            $task = 'numerical';
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
