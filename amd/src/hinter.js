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
 * STACK AI Hinter: a Socratic hint button on STACK quiz-attempt pages.
 *
 * The AI key stays server-side; this module only calls the plugin's own endpoint.
 *
 * @module     local_stackhinter/hinter
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Read the question text from a question element.
 *
 * @param {HTMLElement} que The .que element.
 * @return {string} The plain-text question.
 */
const questionText = (que) => {
    const el = que.querySelector('.qtext') || que.querySelector('.formulation');
    return el ? el.innerText.replace(/\s+/g, ' ').trim() : '';
};

/**
 * Read the student's current answer(s) from a question element.
 *
 * @param {HTMLElement} que The .que element.
 * @return {string} The joined answer text.
 */
const currentAnswer = (que) => {
    const inputs = que.querySelectorAll('.formulation input[type="text"], .formulation textarea');
    const parts = [];
    inputs.forEach((input) => {
        if (input.value && input.value.trim()) {
            parts.push(input.value.trim());
        }
    });
    return parts.join(', ');
};

/**
 * Read any grader feedback shown for a question.
 *
 * @param {HTMLElement} que The .que element.
 * @return {string} The feedback text.
 */
const graderFeedback = (que) => {
    const el = que.querySelector('.stackprtfeedback')
        || que.querySelector('.outcome .feedback')
        || que.querySelector('.feedback');
    return el ? el.innerText.replace(/\s+/g, ' ').trim() : '';
};

/**
 * Identify the live STACK attempt from a question's field names (q{usageid}:{slot}_...), so the
 * server can ground the hint in CAS-verified facts. Returns blanks if it cannot be parsed.
 *
 * @param {HTMLElement} que The .que element.
 * @return {{qubaid: string, slot: string}} The usage id and slot, or blanks.
 */
const qubaSlot = (que) => {
    const input = que.querySelector('.formulation [name^="q"]');
    const match = input && input.name ? input.name.match(/^q(\d+):(\d+)_/) : null;
    return match ? {qubaid: match[1], slot: match[2]} : {qubaid: '', slot: ''};
};

/**
 * Attach a hint button and panel to one STACK question.
 *
 * @param {object} config The hinter configuration passed from PHP.
 * @param {HTMLElement} que The .que.stack element.
 * @return {void}
 */
const attach = (config, que) => {
    if (que.querySelector('.stackhinter-btn')) {
        return;
    }
    const strings = config.strings || {};
    // State: attempt is the escalation level reached; hints are all generated; viewing is the index shown.
    const state = {attempt: 0, hints: [], viewing: 0, capped: false};

    const btn = document.createElement('button');
    btn.type = 'button';
    // Match the STACK "Check" button (btn btn-secondary, default size) so the two sit side by side.
    btn.className = 'btn btn-secondary stackhinter-btn';
    btn.textContent = '💡 ' + (config.label || 'Hint');

    const panel = document.createElement('div');
    panel.className = 'stackhinter-hint';

    // Place the Hint button immediately to the right of the question's Check/submit button, with the
    // hint panel on its own line below the controls. Fall back to a standalone box if there is no Check.
    const check = que.querySelector('.im-controls [type="submit"]') || que.querySelector('[type="submit"].submit');
    if (check) {
        btn.style.marginLeft = '0.5rem';
        check.insertAdjacentElement('afterend', btn);
        const controls = check.closest('.im-controls') || check.parentNode;
        controls.insertAdjacentElement('afterend', panel);
    } else {
        const box = document.createElement('div');
        box.className = 'stackhinter-box';
        box.appendChild(btn);
        box.appendChild(panel);
        const anchor = que.querySelector('.ablock') || que.querySelector('.formulation') || que;
        anchor.appendChild(box);
    }

    // Show the site's AI policy with an Accept button; on accept, record it then retry the hint.
    // Built with text nodes only (never innerHTML) so a hostile policy string cannot inject markup.
    const showPolicy = (data) => {
        panel.textContent = '';
        const intro = document.createElement('p');
        intro.textContent = data.intro || '';
        panel.appendChild(intro);
        if (data.policy) {
            const policy = document.createElement('div');
            policy.className = 'stackhinter-policy-text';
            policy.textContent = data.policy;
            panel.appendChild(policy);
        }
        const accept = document.createElement('button');
        accept.type = 'button';
        accept.className = 'btn btn-primary btn-sm stackhinter-policy-accept';
        accept.textContent = data.acceptlabel || 'Accept';
        accept.addEventListener('click', () => {
            accept.disabled = true;
            const body = new URLSearchParams({
                sesskey: config.sesskey,
                cmid: String(config.cmid || ''),
                action: 'acceptpolicy'
            });
            fetch(config.ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body.toString()
            }).then((response) => response.json())
                .then((res) => {
                    if (res && res.accepted) {
                        postHint();
                    } else {
                        panel.textContent = strings.unavailable || '';
                    }
                    return res;
                })
                .catch(() => {
                    panel.textContent = strings.unavailable || '';
                });
        });
        panel.appendChild(accept);
    };

    // Render the currently-viewed hint plus prev/next navigation through every hint generated so far.
    const render = () => {
        panel.textContent = '';
        panel.classList.add('stackhinter-hint-show');
        if (!state.hints.length) {
            return;
        }
        const text = document.createElement('div');
        text.className = 'stackhinter-hint-text';
        text.textContent = '💡 ' + state.hints[state.viewing];
        panel.appendChild(text);

        if (state.hints.length > 1) {
            const nav = document.createElement('div');
            nav.className = 'stackhinter-hint-nav';
            nav.style.marginTop = '6px';

            const prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'btn btn-link btn-sm stackhinter-nav-prev';
            prev.textContent = '◀';
            prev.title = strings.prev || '';
            prev.setAttribute('aria-label', strings.prev || '');
            prev.disabled = state.viewing === 0;
            prev.addEventListener('click', () => {
                if (state.viewing > 0) {
                    state.viewing -= 1;
                    render();
                }
            });

            const count = document.createElement('span');
            count.className = 'stackhinter-hint-count';
            count.textContent = ' ' + (strings.counter || '{n} / {total}')
                .replace('{n}', String(state.viewing + 1))
                .replace('{total}', String(state.hints.length)) + ' ';

            const next = document.createElement('button');
            next.type = 'button';
            next.className = 'btn btn-link btn-sm stackhinter-nav-next';
            next.textContent = '▶';
            next.title = strings.next || '';
            next.setAttribute('aria-label', strings.next || '');
            next.disabled = state.viewing === state.hints.length - 1;
            next.addEventListener('click', () => {
                if (state.viewing < state.hints.length - 1) {
                    state.viewing += 1;
                    render();
                }
            });

            nav.appendChild(prev);
            nav.appendChild(count);
            nav.appendChild(next);
            panel.appendChild(nav);
        }

        if (state.capped) {
            const done = document.createElement('div');
            done.className = 'stackhinter-hint-done';
            done.style.marginTop = '6px';
            done.textContent = strings.done || '';
            panel.appendChild(done);
        }
    };

    const postHint = () => {
        btn.disabled = true;
        panel.classList.add('stackhinter-hint-show');
        panel.textContent = strings.thinking || '';
        // Use the next escalation level for this request, but only commit it once a hint is actually
        // returned: a policy prompt or a failure must not consume the student's hint quota.
        const thisAttempt = state.attempt + 1;
        const ids = qubaSlot(que);
        const body = new URLSearchParams({
            sesskey: config.sesskey,
            cmid: String(config.cmid || ''),
            question: questionText(que),
            answer: currentAnswer(que),
            feedback: graderFeedback(que),
            attempt: String(thisAttempt),
            qubaid: ids.qubaid,
            slot: ids.slot
        });
        return fetch(config.ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then((response) => response.json())
            .then((data) => {
                if (data.policyrequired) {
                    showPolicy(data);
                } else if (data.hint) {
                    state.attempt = thisAttempt;
                    state.hints.push(data.hint);
                    state.viewing = state.hints.length - 1;
                    state.capped = false;
                    render();
                } else {
                    panel.textContent = data.error || strings.unavailable || '';
                }
                return data;
            })
            .catch(() => {
                panel.textContent = strings.unavailable || '';
            })
            .finally(() => {
                btn.disabled = false;
            });
    };

    btn.addEventListener('click', () => {
        if (config.maxhints && state.attempt >= config.maxhints) {
            // No more new hints; flag it so render() shows the limit note, but keep the hints navigable.
            state.capped = true;
            render();
            return;
        }
        postHint();
    });
};

/**
 * Entry point: wire the hinter into the current quiz-attempt page.
 *
 * @param {object} config The hinter configuration passed from PHP.
 * @return {void}
 */
export const init = (config) => {
    if (!config || !config.ajaxurl) {
        return;
    }
    if (!document.body || document.body.id.indexOf('page-mod-quiz-attempt') !== 0) {
        return;
    }
    // Only attach to STACK questions — never send other question types' content to the AI.
    document.querySelectorAll('.que.stack').forEach((que) => attach(config, que));
};
