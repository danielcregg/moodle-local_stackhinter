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
 * Unit tests for STACK AI Hinter hint post-processing.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Tests for the hint output cleaner and the answer-leak guard.
 *
 * @covers \local_stackhinter\postprocess
 */
final class postprocess_test extends \advanced_testcase {
    /**
     * The output sanitiser strips reasoning blocks, LaTeX and markdown delimiters, echoed prompt labels,
     * leading list markers and chatty preambles, caps a hint at three sentences and capitalises the first
     * word, while leaving an already-clean hint untouched.
     *
     * @return void
     */
    public function test_sanitize(): void {
        // A clean hint is returned unchanged.
        $this->assertSame(
            'Think about the power rule for differentiation.',
            postprocess::sanitize('Think about the power rule for differentiation.')
        );
        // Reasoning/think blocks are removed.
        $this->assertSame(
            'Try factoring the quadratic.',
            postprocess::sanitize("<think>They wrote x=2, missing a root.</think>Try factoring the quadratic.")
        );
        // LaTeX delimiters and a simple \frac are cleaned to plain text.
        $this->assertSame(
            'You should get (1)/(2) of that.',
            postprocess::sanitize('You should get \\(\\frac{1}{2}\\) of that.')
        );
        // Markdown emphasis is stripped.
        $this->assertSame('Use the FOIL method.', postprocess::sanitize('Use the **FOIL** method.'));
        // LaTeX commands keep the readable word without the backslash.
        $this->assertSame(
            'The derivative of sin(x) is cos(x).',
            postprocess::sanitize('The derivative of \\sin(x) is \\cos(x).')
        );
        // Echoed prompt labels and a leading list marker are removed.
        $this->assertSame(
            'Re-check your differentiation.',
            postprocess::sanitize("STUDENT'S ANSWER: x^2\n1. Re-check your differentiation.")
        );
        // Chatty preambles are stripped.
        $this->assertSame('Use the power rule.', postprocess::sanitize("Sure! Here's a hint: Use the power rule."));
        $this->assertSame('Think about the power rule.', postprocess::sanitize('Okay, think about the power rule.'));
        // At most three sentences are kept.
        $this->assertSame('One. Two. Three.', postprocess::sanitize('One. Two. Three. Four. Five.'));
    }

    /**
     * The leak guard replaces a hint that states the model answer with a safe, class-based fallback,
     * and leaves a non-leaking hint untouched.
     *
     * @return void
     */
    public function test_guard_blocks_answer_leak(): void {
        $grounding = ['class' => 'structural', 'answer' => '3x^2'];
        // A hint that states the answer is swapped for the structural fallback.
        $this->assertSame(
            get_string('fallback_structural', 'local_stackhinter'),
            postprocess::guard('The correct derivative is 3x^2, not x^2.', $grounding)
        );
        // A non-leaking hint is returned unchanged.
        $safe = 'Remember the power rule and re-check your exponent.';
        $this->assertSame($safe, postprocess::guard($safe, $grounding));
        // With no model answer in the grounding the hint always passes through.
        $this->assertSame('anything', postprocess::guard('anything', ['class' => 'structural']));
    }
}
