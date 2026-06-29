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
 * Tests for the per-quiz opt-in store.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Tests for the per-quiz opt-in store.
 *
 * @covers \local_stackhinter\quiz_settings
 */
final class quiz_settings_test extends \advanced_testcase {
    /**
     * A quiz with no stored row is treated as not opted in (the safe default).
     *
     * @return void
     */
    public function test_defaults_off_when_no_row(): void {
        $this->resetAfterTest();
        $this->assertFalse(quiz_settings::is_enabled(123456));
        $this->assertFalse(quiz_settings::is_enabled(0));
        $this->assertFalse(quiz_settings::is_enabled(-1));
    }

    /**
     * Enabling, disabling and re-enabling persists and round-trips correctly.
     *
     * @return void
     */
    public function test_set_enabled_round_trips(): void {
        global $DB;
        $this->resetAfterTest();

        quiz_settings::set_enabled(777, true);
        $this->assertTrue(quiz_settings::is_enabled(777));
        $this->assertEquals(1, $DB->count_records('local_stackhinter_quiz', ['cmid' => 777]));

        // Updating an existing quiz must not create a second row.
        quiz_settings::set_enabled(777, false);
        $this->assertFalse(quiz_settings::is_enabled(777));
        $this->assertEquals(1, $DB->count_records('local_stackhinter_quiz', ['cmid' => 777]));

        quiz_settings::set_enabled(777, true);
        $this->assertTrue(quiz_settings::is_enabled(777));
        $this->assertEquals(1, $DB->count_records('local_stackhinter_quiz', ['cmid' => 777]));
    }

    /**
     * Deleting a course-module's row reverts it to the off default.
     *
     * @return void
     */
    public function test_delete_for_cmid(): void {
        $this->resetAfterTest();
        quiz_settings::set_enabled(888, true);
        $this->assertTrue(quiz_settings::is_enabled(888));

        quiz_settings::delete_for_cmid(888);
        $this->assertFalse(quiz_settings::is_enabled(888));
    }

    /**
     * The course_module_deleted observer removes the opt-in row and the hint log for that module.
     *
     * @return void
     */
    public function test_observer_cleans_up_on_module_deletion(): void {
        global $DB, $CFG;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cmid = (int) $quiz->cmid;

        quiz_settings::set_enabled($cmid, true);
        $DB->insert_record('local_stackhinter_hints', (object) [
            'userid' => 1, 'cmid' => $cmid, 'attempt' => 1, 'question' => 'q', 'answer' => 'a',
            'feedback' => '', 'hint' => 'h', 'provider' => 'own:test', 'timecreated' => time(),
        ]);
        $this->assertTrue(quiz_settings::is_enabled($cmid));
        $this->assertEquals(1, $DB->count_records('local_stackhinter_hints', ['cmid' => $cmid]));

        require_once($CFG->dirroot . '/course/lib.php');
        course_delete_module($cmid);

        $this->assertFalse(quiz_settings::is_enabled($cmid));
        $this->assertEquals(0, $DB->count_records('local_stackhinter_hints', ['cmid' => $cmid]));
    }
}
