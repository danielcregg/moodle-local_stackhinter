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
 * Upgrade steps for local_stackhinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the local_stackhinter upgrade from the given old version.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool Always true on success.
 */
function xmldb_local_stackhinter_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026062902) {
        // Move "max hints per question" from a site setting to the per-quiz settings form.
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_stackhinter_quiz');
        $field = new xmldb_field('maxhints', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        unset_config('maxhints', 'local_stackhinter'); // The old site-level setting is now unused.
        upgrade_plugin_savepoint(true, 2026062902, 'local', 'stackhinter');
    }

    if ($oldversion < 2026070107) {
        // The on-device model is no longer configurable (fixed to the validated gemma-2-2b); drop the
        // orphaned setting. On-device sites keep working via the fixed model.
        unset_config('ondevicemodel', 'local_stackhinter');
        upgrade_plugin_savepoint(true, 2026070107, 'local', 'stackhinter');
    }

    return true;
}
