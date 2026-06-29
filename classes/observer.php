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
 * Event observers for local_stackhinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Cleans up the plugin's per-course-module data when a quiz is deleted.
 */
class observer {
    /**
     * On course-module deletion, remove this plugin's rows for that module: the per-quiz opt-in and any
     * logged hints (whose module context no longer resolves once the module is gone, so they could not
     * otherwise be exported or erased through the Privacy API).
     *
     * @param \core\event\course_module_deleted $event The deletion event.
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        $cmid = (int) $event->objectid;
        if ($cmid <= 0) {
            return;
        }
        quiz_settings::delete_for_cmid($cmid);
        $DB->delete_records('local_stackhinter_hints', ['cmid' => $cmid]);
    }
}
