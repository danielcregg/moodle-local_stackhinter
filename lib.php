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
 * Library callbacks for local_stackhinter.
 *
 * Adds per-quiz STACK AI Hinter settings (enable checkbox + max hints per question) to the quiz form, so a
 * teacher turns the hinter on only for the quizzes they choose. The hint button therefore never appears on a
 * quiz nobody opted in — including graded exams. The field is shown only when an administrator has
 * enabled the plugin site-wide (no dead control), and only on quiz modules.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the per-quiz opt-in checkbox to the quiz settings form.
 *
 * @param moodleform_mod $formwrapper The activity settings form wrapper.
 * @param MoodleQuickForm $mform The inner form.
 * @return void
 */
function local_stackhinter_coursemodule_standard_elements($formwrapper, $mform) {
    $current = $formwrapper->get_current();
    if (empty($current->modulename) || $current->modulename !== 'quiz') {
        return; // Only quizzes carry the STACK questions the hinter works on.
    }
    if (!get_config('local_stackhinter', 'enabled')) {
        return; // Only offer the option where it can take effect (the site must enable the hinter first).
    }

    $mform->addElement('header', 'local_stackhinter_header', get_string('perquizheading', 'local_stackhinter'));
    // This section shows on every quiz's settings form, so make clear it only affects STACK questions.
    // Indented to line up with the form's field labels (pl-md-4 = Bootstrap 4, ps-md-4 = Bootstrap 5);
    // normal text colour (no text-muted). Bootstrap utilities are always loaded, unlike plugin CSS.
    $mform->addElement('html', html_writer::div(
        s(get_string('perquizinfo', 'local_stackhinter')),
        'pl-md-4 ps-md-4 mb-2'
    ));
    $mform->addElement(
        'advcheckbox',
        'local_stackhinter_enabled',
        get_string('perquizenable', 'local_stackhinter'),
        '',
        [],
        [0, 1]
    );
    $mform->addHelpButton('local_stackhinter_enabled', 'perquizenable', 'local_stackhinter');
    $mform->setDefault('local_stackhinter_enabled', 0); // Off by default (covers newly created quizzes).

    $mform->addElement(
        'select',
        'local_stackhinter_maxhints',
        get_string('maxhints', 'local_stackhinter'),
        array_combine(range(1, 10), range(1, 10))
    );
    $mform->setDefault('local_stackhinter_maxhints', 3);
    $mform->addHelpButton('local_stackhinter_maxhints', 'maxhints', 'local_stackhinter');
    $mform->disabledIf('local_stackhinter_maxhints', 'local_stackhinter_enabled', 'notchecked');

    // Collapsed by default to keep the quiz form tidy; expanded on quizzes that already use the hinter.
    $cmid = (int) ($current->coursemodule ?? 0);
    $mform->setExpanded(
        'local_stackhinter_header',
        $cmid > 0 && \local_stackhinter\quiz_settings::is_enabled($cmid)
    );
}

/**
 * Preload the stored value when editing an existing quiz, so a routine save cannot silently flip it.
 *
 * @param moodleform_mod $formwrapper The activity settings form wrapper.
 * @param MoodleQuickForm $mform The inner form.
 * @return void
 */
function local_stackhinter_coursemodule_definition_after_data($formwrapper, $mform) {
    $current = $formwrapper->get_current();
    if (empty($current->modulename) || $current->modulename !== 'quiz') {
        return;
    }
    if (!$mform->elementExists('local_stackhinter_enabled')) {
        return; // Field not shown (plugin disabled site-wide) — nothing to preload.
    }
    $cmid = (int) ($current->coursemodule ?? 0);
    if ($cmid > 0) {
        $mform->getElement('local_stackhinter_enabled')
            ->setValue(\local_stackhinter\quiz_settings::is_enabled($cmid) ? 1 : 0);
        $mform->getElement('local_stackhinter_maxhints')
            ->setValue((string) \local_stackhinter\quiz_settings::get_maxhints($cmid));
    }
}

/**
 * Persist the per-quiz opt-in when a quiz is created or updated.
 *
 * @param stdClass $data Submitted module info (includes coursemodule = cmid).
 * @param stdClass $course The course.
 * @return stdClass The (unmodified) module info — Moodle reassigns the return value.
 */
function local_stackhinter_coursemodule_edit_post_actions($data, $course) {
    if (empty($data->modulename) || $data->modulename !== 'quiz') {
        return $data;
    }
    // Only act when the field was actually present on the form. When the plugin is disabled site-wide
    // the field is hidden and absent here — in that case we must NOT clear a teacher's saved choice.
    if (property_exists($data, 'local_stackhinter_enabled') && !empty($data->coursemodule)) {
        $maxhints = isset($data->local_stackhinter_maxhints) ? (int) $data->local_stackhinter_maxhints : 3;
        \local_stackhinter\quiz_settings::save(
            (int) $data->coursemodule,
            !empty($data->local_stackhinter_enabled),
            $maxhints
        );
    }
    return $data;
}
