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
 * Tests for the task classifier that enriches hint guidance.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Unit tests for {@see task_guide}.
 *
 * @covers \local_stackhinter\task_guide
 */
final class task_guide_test extends \advanced_testcase {
    /**
     * Clean questions classify to the right task, and the guide carries a description and a method.
     *
     * @return void
     */
    public function test_classify_known_tasks(): void {
        $cases = [
            'Differentiate f(x) = x^3 with respect to x.' => 'differentiate',
            'Find the derivative of x^2 + 3x.' => 'differentiate',
            'Differentiate f(x) = sin(x) with respect to x.' => 'difftrig',
            'Find the indefinite integral of 2x with respect to x.' => 'integrate',
            'Expand (x+2)(x+3).' => 'expand',
            'Factorise x^2 + 5x + 6.' => 'factor',
            'Solve x^2 - 5x + 6 = 0 for x.' => 'solve',
            'Simplify (2x^2 + 4x) / (2x).' => 'simplify',
        ];
        foreach ($cases as $question => $expected) {
            $guide = task_guide::classify($question);
            $this->assertNotNull($guide, "should classify: {$question}");
            $this->assertSame($expected, $guide['task'], "wrong task for: {$question}");
            $this->assertNotEmpty($guide['desc']);
            $this->assertNotEmpty($guide['method']);
        }
    }

    /**
     * "using" (contains "sin") must not be misread as a trig question.
     *
     * @return void
     */
    public function test_classify_no_trig_false_positive(): void {
        $guide = task_guide::classify('Differentiate the composite function using the chain rule.');
        $this->assertNotNull($guide);
        $this->assertSame('differentiate', $guide['task']);
    }

    /**
     * Questions with no recognisable task return null, so the caller falls back to plain grounding.
     *
     * @return void
     */
    public function test_classify_unknown_returns_null(): void {
        $this->assertNull(task_guide::classify('What is the value of the expression when x = 2?'));
        $this->assertNull(task_guide::classify('Select the correct graph for this function.'));
        $this->assertNull(task_guide::classify(''));
    }

    /**
     * fewshot() returns a task-matched example when classified, and the generic block otherwise.
     *
     * @return void
     */
    public function test_fewshot_matches_task(): void {
        $matched = task_guide::fewshot(task_guide::classify('Factorise x^2 + 5x + 6.'));
        $this->assertStringContainsStringIgnoringCase('factorising', $matched);

        $generic = task_guide::fewshot(null);
        $this->assertStringContainsString('Differentiating x^3', $generic);
    }
}
