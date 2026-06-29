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
 * Privacy provider tests for local_stackhinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Tests the metadata, export and deletion behaviour of the hint log.
 *
 * @covers \local_stackhinter\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The provider must declare both the stored table and the external disclosure.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('local_stackhinter');
        $items = provider::get_metadata($collection)->get_collection();
        // One database_table (the hint log) plus one external_location_link (the AI provider).
        $this->assertCount(2, $items);
    }

    /**
     * A user's hint is discoverable, exportable and deletable in its module context.
     *
     * @return void
     */
    public function test_export_and_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($quiz->cmid);

        $DB->insert_record('local_stackhinter_hints', (object) [
            'userid' => $user->id, 'cmid' => $quiz->cmid, 'attempt' => 1,
            'question' => 'Differentiate x^2', 'answer' => '2x', 'feedback' => 'Correct',
            'hint' => 'Use the power rule.', 'provider' => 'openai', 'timecreated' => time(),
        ]);

        // The context is discoverable for the user.
        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertEqualsCanonicalizing([$context->id], $contextlist->get_contextids());

        // The data exports.
        $this->export_context_data_for_user($user->id, $context, 'local_stackhinter');
        $this->assertTrue(writer::with_context($context)->has_any_data());

        // Deleting for the user removes the row.
        $approved = new approved_contextlist($user, 'local_stackhinter', [$context->id]);
        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records('local_stackhinter_hints', ['userid' => $user->id]));
    }

    /**
     * Deleting all data for a context removes every user's hints there.
     *
     * @return void
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        foreach ([$this->getDataGenerator()->create_user(), $this->getDataGenerator()->create_user()] as $user) {
            $DB->insert_record('local_stackhinter_hints', (object) [
                'userid' => $user->id, 'cmid' => $quiz->cmid, 'attempt' => 1,
                'question' => 'Q', 'answer' => 'A', 'feedback' => 'F', 'hint' => 'H',
                'provider' => 'openai', 'timecreated' => time(),
            ]);
        }

        provider::delete_data_for_all_users_in_context($context);
        $this->assertEquals(0, $DB->count_records('local_stackhinter_hints', ['cmid' => $quiz->cmid]));
    }
}
