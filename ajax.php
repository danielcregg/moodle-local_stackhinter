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
 * Hint endpoint. The browser posts the cmid + question + the student's current answer; we verify the
 * user may attempt that quiz, call the AI server-side (the key never leaves the server), log the hint
 * and return it as JSON. A second action ('acceptpolicy') records the user's explicit acceptance of
 * Moodle's AI User Policy when the core-AI backend is in use.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$cmid   = required_param('cmid', PARAM_INT);
// Either 'hint' (default) or 'acceptpolicy' (record the user's explicit AI-policy acceptance).
$action = optional_param('action', 'hint', PARAM_ALPHA);

// Access control: real module context + the user must be allowed to attempt this quiz.
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, false, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('mod/quiz:attempt', $context);

global $DB, $USER;
header('Content-Type: application/json; charset=utf-8');

if (!get_config('local_stackhinter', 'enabled')) {
    echo json_encode(['error' => get_string('hinttemporary', 'local_stackhinter')]);
    die();
}

// Per-quiz opt-in: refuse if this quiz has not enabled the hinter. This defends the endpoint itself,
// not just the JS injection — a crafted POST with a valid sesskey and cmid must not bypass the choice.
if (!\local_stackhinter\quiz_settings::is_enabled($cmid)) {
    echo json_encode(['error' => get_string('hinttemporary', 'local_stackhinter')]);
    die();
}

// Record the user's explicit acceptance of the AI User Policy (an explicit action), then stop. This
// mirrors core's set_policy_status: it needs moodle/ai:acceptpolicy in the user context, and reports
// the real outcome. Handled before the hint-only required params (the accept POST sends none).
if ($action === 'acceptpolicy') {
    require_capability('moodle/ai:acceptpolicy', \core\context\user::instance($USER->id));
    echo json_encode(['accepted' => \local_stackhinter\core_ai::record_policy((int) $USER->id, $context)]);
    die();
}

// Hint request parameters.
$question = core_text::substr(required_param('question', PARAM_RAW), 0, 1500);
$answer   = core_text::substr(required_param('answer', PARAM_RAW), 0, 500);
$feedback = core_text::substr(optional_param('feedback', '', PARAM_RAW), 0, 1000);
$attempt  = max(1, optional_param('attempt', 1, PARAM_INT));
// Optional: identify the live STACK attempt so we can ground the hint in a CAS-verified diagnosis.
$qubaid   = optional_param('qubaid', 0, PARAM_INT);
$slot     = optional_param('slot', 0, PARAM_INT);

// Bind the hint to a real STACK attempt the user owns in THIS quiz, so the endpoint cannot be used as a
// free-form AI proxy (a valid sesskey on an opted-in quiz is not sufficient on its own).
if (!\local_stackhinter\stack_grounding::owns_attempt($cm, (int) $USER->id, $qubaid, $slot)) {
    echo json_encode(['error' => get_string('hinttemporary', 'local_stackhinter')]);
    die();
}

try {
    // Server-side abuse/cost cap: a hard ceiling on hints per user per quiz module.
    // (The per-question escalation cap in the JS is just UX.)
    $maxhints = \local_stackhinter\quiz_settings::get_maxhints($cmid);
    $ceiling  = max(10, $maxhints * 20);
    if ($DB->count_records('local_stackhinter_hints', ['userid' => $USER->id, 'cmid' => $cmid]) >= $ceiling) {
        echo json_encode(['hint' => get_string('hintlimitreached', 'local_stackhinter')]);
        die();
    }

    // Ground the hint in a CAS-verified diagnosis when we can resolve the live STACK attempt
    // (ownership is verified inside for_request; any failure falls back to feedback-only hinting).
    $grounding = \local_stackhinter\stack_grounding::for_request($cm, (int) $USER->id, $qubaid, $slot, $answer) ?? [];

    $hint = \local_stackhinter\ai_client::hint($question, $answer, $feedback, $attempt, $grounding, $context, (int) $USER->id);

    // Log the interaction — the data substrate for teaching analytics. Record the backend that
    // actually handled it ('core_ai' or 'own:<provider>'), not just the configured own provider.
    $DB->insert_record('local_stackhinter_hints', (object) [
        'userid' => $USER->id, 'cmid' => $cmid, 'attempt' => $attempt,
        'question' => $question, 'answer' => $answer, 'feedback' => $feedback,
        'hint' => $hint, 'provider' => \local_stackhinter\ai_client::backend_label($context),
        'timecreated' => time(),
    ]);

    echo json_encode(['hint' => $hint]);
} catch (\moodle_exception $e) {
    // The core AI backend needs the AI policy accepted first: tell the browser so it can prompt.
    if ($e->errorcode === 'aipolicyrequired') {
        echo json_encode([
            'policyrequired' => true,
            'intro' => get_string('policyintro', 'local_stackhinter'),
            'policy' => \local_stackhinter\core_ai::policy_text(),
            'acceptlabel' => get_string('policyaccept', 'local_stackhinter'),
        ]);
        die();
    }
    debugging('local_stackhinter hint failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode(['error' => get_string('hinttemporary', 'local_stackhinter')]);
} catch (\Throwable $e) {
    // Log the detail server-side; return a generic message (no upstream/provider leak).
    debugging('local_stackhinter hint failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode(['error' => get_string('hinttemporary', 'local_stackhinter')]);
}
