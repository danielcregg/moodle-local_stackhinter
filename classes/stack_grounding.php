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
 * Computes a CAS-verified, non-revealing diagnosis to ground an AI hint for a STACK question.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * "Grounded" hinting: rather than letting the LLM do the algebra (unreliable for maths), Moodle's own
 * STACK/Maxima classifies how the student's answer relates to a correct one, and only that qualitative
 * CLASS is given to the LLM. The model answer and the exact difference are computed but NEVER leave the
 * server — so the hint cannot leak the answer even if the model ignores instructions (a student who
 * enters 0, say, learns only "your answer differs structurally", not the negated model answer).
 *
 * Safety: the student value enters the CAS exactly as STACK does for grading — the input-system-
 * validated `contentsmodified`, injected via a stack_secure_loader, never as raw text or teacher
 * source. Everything is feature-probed and wrapped: any failure returns null and the caller falls back
 * to feedback-only hinting.
 */
class stack_grounding {
    /** @var string[] The diagnosis classes, keyed by the CAS classification code. */
    const CLASSES = [0 => 'equivalent', 1 => 'constant', 2 => 'structural'];

    /**
     * Whether the installed qtype_stack exposes the CAS APIs this grounding needs.
     *
     * @return bool True if grounding can be attempted.
     */
    public static function supported(): bool {
        return class_exists('qtype_stack_question')
            && class_exists('stack_cas_session2')
            && class_exists('stack_ast_container')
            && class_exists('stack_secure_loader')
            && class_exists('stack_cas_security')
            && class_exists('stack_input');
    }

    /**
     * Whether a question usage + slot is a STACK question in one of this user's attempts at this quiz.
     *
     * Used to bind a hint request to a real, owned STACK attempt (so the endpoint cannot be abused as a
     * free-form AI proxy), to refuse a forged usage id, and to reject a bogus slot that is not a STACK
     * question in that usage.
     *
     * @param \cm_info $cm The quiz course module.
     * @param int $userid The requesting user.
     * @param int $qubaid The question usage id.
     * @param int $slot The question slot within the usage.
     * @return bool True if the slot is a STACK question in one of this user's attempts at this quiz.
     */
    public static function owns_attempt(\cm_info $cm, int $userid, int $qubaid, int $slot): bool {
        global $DB;
        if ($qubaid <= 0 || $slot <= 0) {
            return false;
        }
        // The usage must be one of THIS user's attempts at THIS quiz.
        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid], 'id, userid, quiz');
        if (!$attempt || (int) $attempt->userid !== $userid || (int) $attempt->quiz !== (int) $cm->instance) {
            return false;
        }
        // ...and the slot must be a real STACK question within that usage (not a guessed slot number).
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {question_attempts} qatt
               JOIN {question} q ON q.id = qatt.questionid
              WHERE qatt.questionusageid = :qubaid AND qatt.slot = :slot AND q.qtype = 'stack'",
            ['qubaid' => $qubaid, 'slot' => $slot]
        );
    }

    /**
     * Resolve and verify a question attempt from a hint request, then classify the student's answer.
     *
     * The question usage must belong to one of this user's attempts at this quiz, otherwise we refuse
     * (a forged usage id simply yields no grounding).
     *
     * @param \cm_info $cm The quiz course module.
     * @param int $userid The requesting user.
     * @param int $qubaid The question usage id (from the field prefix q{usageid}:{slot}_).
     * @param int $slot The question slot within the usage.
     * @param string $studentanswer The answer the student currently has typed (the one they see).
     * @return array|null ['class' => string] or null to fall back to feedback-only hinting.
     */
    public static function for_request(\cm_info $cm, int $userid, int $qubaid, int $slot, string $studentanswer): ?array {
        // The usage must be one of THIS user's attempts at THIS quiz (a forged usage id yields no grounding).
        if (trim($studentanswer) === '' || !self::owns_attempt($cm, $userid, $qubaid, $slot)) {
            return null;
        }
        try {
            $quba = \question_engine::load_questions_usage_by_activity($qubaid);
            $qa = $quba->get_question_attempt($slot);
        } catch (\Throwable $e) {
            return null;
        }
        return self::for_question_attempt($qa, $studentanswer);
    }

    /**
     * Classify the student's current answer against a correct one for a STACK question attempt.
     *
     * The probe runs after the question is loaded (so the legacy qtype_stack classes are present), and
     * only for single-input questions (the browser sends one combined answer).
     *
     * @param \question_attempt $qa The question attempt.
     * @param string $studentanswer The answer the student currently has typed.
     * @return array|null ['class' => string] or null if unavailable.
     */
    public static function for_question_attempt(\question_attempt $qa, string $studentanswer): ?array {
        try {
            $question = $qa->get_question();
            if (!($question instanceof \qtype_stack_question) || !self::supported()) {
                return null;
            }
            if (!empty($question->runtimeerrors) || count($question->inputs) !== 1) {
                return null;
            }
            $name = array_key_first($question->inputs);
            // Validate the student's CURRENT answer through STACK's own input system (safe + current,
            // rather than a possibly-stale last-submitted response).
            $state = $question->get_input_state($name, [$name => $studentanswer]);
            if (!in_array($state->status, [\stack_input::SCORE, \stack_input::VALID], true)) {
                return null;
            }
            $model = trim((string) $question->get_ta_for_input($name));
            if (trim((string) $state->contentsmodified) === '' || $model === '') {
                return null;
            }
            $code = self::cas_classify($question, $name, $state, $model);
            if ($code === null || !isset(self::CLASSES[$code])) {
                return null;
            }
            return ['class' => self::CLASSES[$code]];
        } catch (\Throwable $e) {
            debugging('local_stackhinter grounding failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Classify ratsimp(student - model) inside the question's CAS context WITHOUT exposing any value.
     *
     * Returns 0 (equivalent), 1 (differs by a constant only) or 2 (differs by a term involving the
     * variable). The student value comes from the validated input system and is injected via a
     * stack_secure_loader exactly as STACK does for grading; the model answer is the trusted teacher
     * answer. Neither the model answer nor the difference is returned to the caller.
     *
     * @param \qtype_stack_question $question The instantiated question.
     * @param string $inputname The input variable name (e.g. ans1).
     * @param \stdClass $state The input state (for contentsmodified + simp).
     * @param string $model The teacher answer value.
     * @return int|null The classification code, or null on any failure.
     */
    private static function cas_classify(\qtype_stack_question $question, string $inputname, $state, string $model): ?int {
        try {
            $studentval = (string) $state->contentsmodified;
            if (isset($state->simp) && $state->simp === true) {
                $studentval = 'ev(' . $studentval . ',simp)';
            }
            $session = new \stack_cas_session2([], $question->options, (int) $question->seed);
            $question->add_question_vars_to_session($session);
            // Match STACK's grading: raw inputs are loaded unsimplified.
            $session->add_statement(new \stack_secure_loader('simp:false', 'stackhinter-simp'));
            // Student value: validated input, injected the same secure way STACK uses for grading.
            $session->add_statement(new \stack_secure_loader($inputname . ':' . $studentval, 'stackhinter-input'));
            // The difference (kept server-side) and a non-revealing classification of it.
            $diff = \stack_ast_container::make_from_teacher_source(
                'stackhinterdiff:ratsimp((' . $inputname . ')-(' . $model . '))',
                '',
                new \stack_cas_security()
            );
            $session->add_statement($diff);
            $code = \stack_ast_container::make_from_teacher_source(
                'stackhintercode:if is(stackhinterdiff=0) then 0 '
                . 'elseif emptyp(listofvars(stackhinterdiff)) then 1 else 2',
                '',
                new \stack_cas_security()
            );
            $session->add_statement($code);
            if (!$session->get_valid()) {
                return null;
            }
            $session->instantiate();
            if (!$code->is_correctly_evaluated()) {
                return null;
            }
            $value = trim((string) $code->get_value());
            return ($value === '0' || $value === '1' || $value === '2') ? (int) $value : null;
        } catch (\Throwable $e) {
            debugging('local_stackhinter cas_classify failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}
