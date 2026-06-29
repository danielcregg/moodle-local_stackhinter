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
 * Admin settings for STACK AI Hinter plugin.
 *
 * The plugin is disabled by default and makes no external calls until an administrator enables it,
 * chooses a provider/model and supplies an API key. All keys are stored server-side.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_stackhinter', get_string('pluginname', 'local_stackhinter'));
    $ADMIN->add('localplugins', $settings);

    // Disabled by default: nothing is injected and no external call is made until switched on.
    $settings->add(new admin_setting_configcheckbox(
        'local_stackhinter/enabled',
        get_string('enabled', 'local_stackhinter'),
        get_string('enabled_desc', 'local_stackhinter'),
        0
    ));

    // Which AI backend to use: Moodle's built-in core AI (reuses a site-configured provider/key, with
    // Moodle's AI policy + logging) or this plugin's own provider/key below. Auto prefers core when a
    // core provider is configured, otherwise uses this plugin's own provider.
    $settings->add(new admin_setting_configselect(
        'local_stackhinter/aibackend',
        get_string('aibackend', 'local_stackhinter'),
        get_string('aibackend_desc', 'local_stackhinter'),
        'auto',
        [
            'auto' => get_string('aibackend_auto', 'local_stackhinter'),
            'core' => get_string('aibackend_core', 'local_stackhinter'),
            'own'  => get_string('aibackend_own', 'local_stackhinter'),
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_stackhinter/ownheading',
        get_string('ownheading', 'local_stackhinter'),
        get_string('ownheading_desc', 'local_stackhinter')
    ));

    $settings->add(new admin_setting_configselect(
        'local_stackhinter/provider',
        get_string('provider', 'local_stackhinter'),
        get_string('provider_desc', 'local_stackhinter'),
        'openai',
        [
            'openai'   => 'OpenAI',
            'claude'   => 'Anthropic Claude',
            'gemini'   => 'Google Gemini',
            'groq'     => 'Groq',
            'deepseek' => 'DeepSeek',
            'mistral'  => 'Mistral',
            'cerebras' => 'Cerebras',
            'zenmux'   => 'ZenMux (OpenAI-compatible gateway)',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_stackhinter/model',
        get_string('model', 'local_stackhinter'),
        get_string('model_desc', 'local_stackhinter'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_stackhinter/apikey',
        get_string('apikey', 'local_stackhinter'),
        get_string('apikey_desc', 'local_stackhinter'),
        ''
    ));
}
