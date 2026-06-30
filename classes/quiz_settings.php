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
 * Per-quiz opt-in store for STACK AI Hinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Reads and writes the per-quiz (per course-module) opt-in flag in {local_stackhinter_quiz}.
 *
 * The safe default is OFF: a quiz with no row is treated as not opted in, so the hint button never appears on
 * a quiz a teacher has not explicitly enabled. This is the single source of truth checked by the JS
 * injection (hook_callbacks) and by the hint endpoint (ajax.php).
 */
class quiz_settings {
    /**
     * Whether the hinter is enabled for a given quiz course-module.
     *
     * @param int $cmid The quiz course-module id.
     * @return bool True only if an explicit enabled row exists.
     */
    public static function is_enabled(int $cmid): bool {
        global $DB;
        if ($cmid <= 0) {
            return false;
        }
        return (bool) $DB->get_field('local_stackhinter_quiz', 'enabled', ['cmid' => $cmid]);
    }

    /**
     * The per-quiz "max hints per question" value (default 3 when not set).
     *
     * @param int $cmid The quiz course-module id.
     * @return int The max hints per question (at least 1).
     */
    public static function get_maxhints(int $cmid): int {
        global $DB;
        if ($cmid <= 0) {
            return 3;
        }
        $value = $DB->get_field('local_stackhinter_quiz', 'maxhints', ['cmid' => $cmid]);
        return $value ? max(1, (int) $value) : 3;
    }

    /**
     * Save (insert or update) the per-quiz settings: opt-in flag + max hints per question.
     *
     * @param int $cmid The quiz course-module id.
     * @param bool $enabled Whether the hinter is enabled for this quiz.
     * @param int $maxhints Max escalating hints per question (clamped to 1..50).
     * @return void
     */
    public static function save(int $cmid, bool $enabled, int $maxhints = 3): void {
        global $DB;
        if ($cmid <= 0) {
            return;
        }
        $maxhints = max(1, min(50, $maxhints));
        $existing = $DB->get_record('local_stackhinter_quiz', ['cmid' => $cmid]);
        if ($existing) {
            $existing->enabled = $enabled ? 1 : 0;
            $existing->maxhints = $maxhints;
            $existing->timemodified = time();
            $DB->update_record('local_stackhinter_quiz', $existing);
        } else {
            $DB->insert_record('local_stackhinter_quiz', (object) [
                'cmid' => $cmid,
                'enabled' => $enabled ? 1 : 0,
                'maxhints' => $maxhints,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Remove the stored row for a course-module (called when the quiz is deleted).
     *
     * @param int $cmid The quiz course-module id.
     * @return void
     */
    public static function delete_for_cmid(int $cmid): void {
        global $DB;
        if ($cmid <= 0) {
            return;
        }
        $DB->delete_records('local_stackhinter_quiz', ['cmid' => $cmid]);
    }
}
