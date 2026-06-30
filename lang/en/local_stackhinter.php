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
 * English language strings for local_stackhinter.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aibackend'] = 'AI backend';
$string['aibackend_auto'] = 'Auto: Moodle\'s built-in AI if a provider is configured, otherwise this plugin\'s own provider';
$string['aibackend_core'] = 'Moodle\'s built-in AI (reuses the site\'s configured provider and key)';
$string['aibackend_desc'] = 'Where hints are generated. "Moodle\'s built-in AI" reuses a provider configured under Site administration > AI, so no separate key is needed and Moodle\'s AI policy and logging apply. "This plugin\'s own provider" uses the provider, model and key set below. "Auto" prefers Moodle\'s AI when a core provider is available, otherwise uses this plugin\'s own provider.';
$string['aibackend_own'] = 'This plugin\'s own provider and key (set below)';
$string['aiempty'] = 'The AI returned an empty response.';
$string['aifailed'] = 'The AI request failed: {$a}';
$string['aipolicyrequired'] = 'Please accept this site\'s AI usage policy to use hints.';
$string['apikey'] = 'AI API key';
$string['apikey_desc'] = 'Stored server-side and never sent to the browser; the plugin calls the provider from the server. Need a free key? See the note under "This plugin\'s own AI provider" above.';
$string['enabled'] = 'Enable STACK AI Hinter';
$string['enabled_desc'] = 'Make STACK AI Hinter available on this site. When on, teachers can switch the hint button on per quiz (off by default) in each quiz\'s settings, so it never appears on a quiz nobody opted in, including exams. Stays off until you also configure an AI backend (a provider, model and API key below, or Moodle\'s built-in AI).';
$string['hintbutton'] = 'Hint';
$string['hintcounter'] = 'Hint {n} of {total}';
$string['hintlimitreached'] = 'You have reached the hint limit for this quiz. Keep going, you can do it!';
$string['hintnext'] = 'Next hint';
$string['hintprev'] = 'Previous hint';
$string['hintsdone'] = 'You have used all available hints for this question.';
$string['hinttemporary'] = 'Hints are temporarily unavailable. Please try again in a moment.';
$string['hintthinking'] = 'Generating a hint…';
$string['hintunavailable'] = 'Hints are unavailable right now. Please try again.';
$string['maxhints'] = 'Max hints per question';
$string['maxhints_help'] = 'How many escalating hints a student may request per question on this quiz (1 to 10). The default is 3.';
$string['model'] = 'Model';
$string['model_desc'] = 'Model id for the chosen provider, for example gpt-4o-mini, gemini-2.5-flash, or claude-3-5-haiku. Free options include any OpenRouter model id ending in :free, or gemini-2.5-flash on Google\'s free tier.';
$string['nokey'] = 'No AI API key is configured for STACK AI Hinter.';
$string['nomodel'] = 'No AI model is configured for STACK AI Hinter.';
$string['noprovider'] = 'No valid AI provider is configured for STACK AI Hinter.';
$string['ownheading'] = 'This plugin\'s own AI provider';
$string['ownheading_desc'] = 'Used when the AI backend is "This plugin\'s own provider", or "Auto" with no core AI provider configured. Ignored when Moodle\'s built-in AI is used.<br><br><b>Fastest free setup (about 2 minutes, no cost):</b><br>1. Create a free API key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.<br>2. Set <i>AI provider</i> to <i>OpenRouter</i>, paste the key into <i>AI API key</i>, and set <i>Model</i> to any model id ending in <code>:free</code> from the <a href="https://openrouter.ai/models?max_price=0" target="_blank" rel="noopener">free models list</a> (for example <code>meta-llama/llama-3.3-70b-instruct:free</code>).<br>3. Tick <i>Enable STACK AI Hinter</i> above.<br>Google Gemini works the same way with a free key from <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> (provider <i>Google Gemini</i>, model <code>gemini-2.5-flash</code>).';
$string['perquizenable'] = 'Enable STACK AI Hinter on this quiz';
$string['perquizenable_help'] = 'When ticked, students see the AI hint button on this quiz\'s STACK questions. It is off by default. Leave it off for graded tests and exams. This only has an effect if a site administrator has enabled STACK AI Hinter for the whole site.';
$string['perquizheading'] = 'STACK AI Hinter';
$string['perquizinfo'] = 'These settings apply only to STACK questions in this quiz. They have no effect on quizzes without STACK questions.';
$string['pluginname'] = 'STACK AI Hinter';
$string['policyaccept'] = 'Accept and continue';
$string['policyintro'] = 'Hints on this site are generated through Moodle\'s AI. Please review and accept the AI usage policy to continue.';
$string['privacy:metadata:hints'] = 'A log of hints shown to students, kept to improve teaching.';
$string['privacy:metadata:hints:answer'] = 'The answer the student had typed when requesting the hint.';
$string['privacy:metadata:hints:attempt'] = 'The hint attempt number for the question.';
$string['privacy:metadata:hints:cmid'] = 'The course module (quiz) in which the hint was shown.';
$string['privacy:metadata:hints:feedback'] = 'The grader feedback shown for the student answer.';
$string['privacy:metadata:hints:hint'] = 'The hint text generated and shown to the student.';
$string['privacy:metadata:hints:provider'] = 'The AI provider used to generate the hint.';
$string['privacy:metadata:hints:question'] = 'The question text the student was working on.';
$string['privacy:metadata:hints:timecreated'] = 'The time the hint was generated.';
$string['privacy:metadata:hints:userid'] = 'The user who received the hint.';
$string['privacy:metadata:provider'] = 'To generate a hint, the question, the student answer and the grader feedback are sent to an external AI provider. For STACK questions a short qualitative diagnosis of the student answer (whether it is equivalent to a correct answer, differs by a constant, or differs structurally), computed by Maxima from the student answer, is also sent to ground the hint; the model answer and exact values are never sent. When the AI backend is set to Moodle\'s built-in AI, the request is handled by Moodle\'s core AI subsystem (which governs that disclosure) instead of this plugin\'s own provider. This plugin stores no data on the provider.';
$string['privacy:metadata:provider:answer'] = 'The answer the student has currently typed.';
$string['privacy:metadata:provider:diagnosis'] = 'A qualitative classification of the student answer (equivalent / off by a constant / structurally different), derived from the student answer by Maxima.';
$string['privacy:metadata:provider:feedback'] = 'The grader feedback for the student answer.';
$string['privacy:metadata:provider:question'] = 'The question text the student is working on.';
$string['provider'] = 'AI provider';
$string['provider_desc'] = 'Which external AI service generates the hints. You must also set a model and API key. For a free setup, OpenRouter (models ending in :free) or Google Gemini both work at no cost; see the note under "This plugin\'s own AI provider" above.';
$string['region'] = 'AWS region (Bedrock)';
$string['region_desc'] = 'The AWS region for the AWS Bedrock provider, for example us-east-1 or eu-west-1. Other providers ignore this. Bedrock uses an OpenAI-compatible endpoint, so its API key is an Amazon Bedrock API key and the model is a Bedrock model id such as openai.gpt-oss-20b-1:0 or us.anthropic.claude-sonnet-4-6.';
