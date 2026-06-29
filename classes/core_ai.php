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
 * Thin adapter over Moodle's core AI subsystem (\core_ai) so the tutor can reuse a site's already
 * configured AI provider instead of needing its own API key.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Wraps the core AI generate_text action. The core subsystem (Moodle 4.5+) brokers the request to the
 * site's configured provider, so the API key, provider management and central logging are Moodle's —
 * not this plugin's. Everything is feature-probed and wrapped: when core AI is unavailable this simply
 * reports so, and the caller uses the plugin's own provider instead.
 *
 * Note: core's process_action() does NOT enforce the AI User Policy (placements do), so the caller is
 * responsible for checking policy_accepted() before generating, and for recording acceptance only on an
 * explicit user action.
 */
class core_ai {
    /**
     * Whether the core AI subsystem is present AND a provider is enabled+configured for generate_text.
     *
     * @return bool True if a core AI text generation can be attempted.
     */
    public static function available(): bool {
        if (!class_exists('\core_ai\manager') || !class_exists('\core_ai\aiactions\generate_text')) {
            return false;
        }
        try {
            $action = \core_ai\aiactions\generate_text::class;
            $providers = \core_ai\manager::get_providers_for_actions([$action], true);
            return !empty($providers[$action]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the given user has accepted the site's AI User Policy.
     *
     * @param int $userid The user id.
     * @return bool True if the policy has been accepted.
     */
    public static function policy_accepted(int $userid): bool {
        if (!class_exists('\core_ai\manager')) {
            return false;
        }
        try {
            return (bool) \core_ai\manager::get_user_policy_status($userid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Record the user's acceptance of the AI User Policy (call only on an explicit user action).
     *
     * @param int $userid The user id.
     * @param \context $context The context acceptance happened in.
     * @return bool True on success.
     */
    public static function record_policy(int $userid, \context $context): bool {
        if (!class_exists('\core_ai\manager')) {
            return false;
        }
        try {
            return (bool) \core_ai\manager::user_policy_accepted($userid, $context->id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The site's AI policy text as plain text (for informed consent), or '' if unavailable.
     *
     * Moodle 4.5 stores the policy body in the core_ai `userpolicy` language string (HTML); an admin may
     * override it via the core_ai `policy` config. We return plain text because the tutor renders it with
     * textContent (never innerHTML).
     *
     * @return string The plain-text policy, or ''.
     */
    public static function policy_text(): string {
        $raw = trim((string) get_config('core_ai', 'policy'));
        if ($raw === '') {
            try {
                $raw = trim((string) get_string('userpolicy', 'core_ai'));
            } catch (\Throwable $e) {
                $raw = '';
            }
        }
        if ($raw === '') {
            return '';
        }
        return trim(html_to_text($raw, 0, false));
    }

    /**
     * Generate text via the core AI subsystem.
     *
     * @param \context $context The context the request is made in.
     * @param int $userid The requesting user id.
     * @param string $prompt The full prompt (system + user combined).
     * @return string|null The generated text, or null if the call failed.
     */
    public static function generate_text(\context $context, int $userid, string $prompt): ?string {
        try {
            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
            );
            $manager = \core\di::get(\core_ai\manager::class);
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                debugging('local_stackhinter core AI failed: ' . $response->get_errormessage(), DEBUG_DEVELOPER);
                return null;
            }
            $text = trim((string) ($response->get_response_data()['generatedcontent'] ?? ''));
            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            debugging('local_stackhinter core AI error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}
