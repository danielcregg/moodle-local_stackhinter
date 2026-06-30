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
 * Unit tests for STACK AI Hinter server-side client.
 *
 * @package    local_stackhinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackhinter;

/**
 * Tests that ai_client refuses to call any provider until it is fully configured.
 *
 * @covers \local_stackhinter\ai_client
 */
final class ai_client_test extends \advanced_testcase {
    /**
     * With no provider configured, hint() must throw before any network call.
     *
     * @return void
     */
    public function test_hint_requires_a_provider(): void {
        $this->resetAfterTest();
        set_config('provider', '', 'local_stackhinter');
        set_config('model', 'some-model', 'local_stackhinter');
        set_config('apikey', 'some-key', 'local_stackhinter');
        try {
            ai_client::hint('question', 'answer', 'feedback', 1);
            $this->fail('Expected a moodle_exception for a missing provider.');
        } catch (\moodle_exception $e) {
            $this->assertSame('noprovider', $e->errorcode);
        }
    }

    /**
     * With no model configured, hint() must throw before any network call.
     *
     * @return void
     */
    public function test_hint_requires_a_model(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_stackhinter');
        set_config('model', '', 'local_stackhinter');
        set_config('apikey', 'some-key', 'local_stackhinter');
        try {
            ai_client::hint('question', 'answer', 'feedback', 1);
            $this->fail('Expected a moodle_exception for a missing model.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nomodel', $e->errorcode);
        }
    }

    /**
     * With no API key configured, hint() must throw before any network call.
     *
     * @return void
     */
    public function test_hint_requires_a_key(): void {
        $this->resetAfterTest();
        set_config('provider', 'openai', 'local_stackhinter');
        set_config('model', 'gpt-4o-mini', 'local_stackhinter');
        set_config('apikey', '', 'local_stackhinter');
        try {
            ai_client::hint('question', 'answer', 'feedback', 1);
            $this->fail('Expected a moodle_exception for a missing key.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nokey', $e->errorcode);
        }
    }

    /**
     * An unknown provider id is treated the same as no provider.
     *
     * @return void
     */
    public function test_hint_rejects_an_unknown_provider(): void {
        $this->resetAfterTest();
        set_config('provider', 'not-a-real-provider', 'local_stackhinter');
        set_config('model', 'gpt-4o-mini', 'local_stackhinter');
        set_config('apikey', 'some-key', 'local_stackhinter');
        try {
            ai_client::hint('question', 'answer', 'feedback', 1);
            $this->fail('Expected a moodle_exception for an unknown provider.');
        } catch (\moodle_exception $e) {
            $this->assertSame('noprovider', $e->errorcode);
        }
    }

    /**
     * The AI backend resolves to the administrator's explicit choice; "auto" with no request context
     * falls back to this plugin's own provider (core AI needs a real context to be selectable).
     *
     * @return void
     */
    public function test_resolve_backend(): void {
        $this->resetAfterTest();

        set_config('aibackend', 'own', 'local_stackhinter');
        $this->assertSame('own', ai_client::resolve_backend(null));

        set_config('aibackend', 'core', 'local_stackhinter');
        $this->assertSame('core', ai_client::resolve_backend(null));

        set_config('aibackend', 'auto', 'local_stackhinter');
        $this->assertSame('own', ai_client::resolve_backend(null));
    }

    /**
     * The backend label recorded against each hint names either the core subsystem or the own provider.
     *
     * @return void
     */
    public function test_backend_label(): void {
        $this->resetAfterTest();

        set_config('aibackend', 'own', 'local_stackhinter');
        set_config('provider', 'openai', 'local_stackhinter');
        $this->assertSame('own:openai', ai_client::backend_label(null));

        set_config('aibackend', 'core', 'local_stackhinter');
        $this->assertSame('core_ai', ai_client::backend_label(null));
    }

    /**
     * The output sanitiser strips reasoning blocks, LaTeX and markdown delimiters, echoed prompt
     * labels and leading list markers, while leaving an already-clean hint untouched.
     *
     * @return void
     */
    public function test_sanitize(): void {
        // A clean hint is returned unchanged.
        $this->assertSame(
            'Think about the power rule for differentiation.',
            ai_client::sanitize('Think about the power rule for differentiation.')
        );
        // Reasoning/think blocks are removed.
        $this->assertSame(
            'Try factoring the quadratic.',
            ai_client::sanitize("<think>They wrote x=2, missing a root.</think>Try factoring the quadratic.")
        );
        // LaTeX delimiters and a simple \frac are cleaned to plain text.
        $this->assertSame(
            'You should get (1)/(2) of that.',
            ai_client::sanitize('You should get \\(\\frac{1}{2}\\) of that.')
        );
        // Markdown emphasis is stripped.
        $this->assertSame('Use the FOIL method.', ai_client::sanitize('Use the **FOIL** method.'));
        // Echoed prompt labels and a leading list marker are removed.
        $this->assertSame(
            'Re-check your differentiation.',
            ai_client::sanitize("STUDENT'S ANSWER: x^2\n1. Re-check your differentiation.")
        );
    }
}
