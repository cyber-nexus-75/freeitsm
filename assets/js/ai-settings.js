/**
 * Drives the reusable AI settings panel (includes/ai_settings_panel.php).
 *
 * Auto-initialises every [data-ai-panel] on the page: hydrates from
 * api/system/ai/get_settings.php, swaps the model suggestions when the
 * provider changes (curated list for Anthropic/OpenAI, live OpenRouter
 * catalogue with pricing for OpenRouter), and saves/tests via the shared
 * endpoints. No per-module code — the namespace + endpoints come from
 * data-attributes on the panel element.
 *
 * The model picker is a self-contained dropdown (NOT a native <datalist>):
 * a long OpenRouter list rendered via <datalist> made the browser scroll the
 * overflow:hidden page body when focused, pushing the header off-screen. This
 * custom menu is absolutely positioned inside the panel with its own capped
 * scroll, so it can never move the page.
 */
(function () {
    'use strict';

    /**
     * Translates a key under common.ai, falling back to the given English when there is
     * no translation.
     */
    function tr(key, fallback) {
        try {
            if (window.t) {
                var v = window.t('common.ai.' + key);
                if (v && v !== 'common.ai.' + key) return v;
            }
        } catch (e) { /* ignore */ }
        return fallback;
    }

    /** Shows a toast when the page has the shared toast helper. */
    function toast(msg, type) {
        if (typeof showToast === 'function') showToast(msg, type || 'info');
    }

    /** Escapes text for safe use inside HTML. */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    /**
     * Turns an OpenRouter per-token price into dollars per million tokens, such as
     * "$3.00" - null when there is no price.
     */
    function fmtPrice(perToken) {
        if (perToken === null || perToken === undefined || perToken === '') return null;
        var perM = parseFloat(perToken) * 1000000;
        if (isNaN(perM)) return null;
        return '$' + (perM >= 1 ? perM.toFixed(2) : perM.toPrecision(2));
    }

    /**
     * Wires one AI settings panel: the provider switch, the model dropdown, the Azure
     * fields, Save and Test, then fills it from the saved settings for its namespace.
     */
    function initPanel(panel) {
        var ns      = panel.getAttribute('data-ns');
        var apiBase = panel.getAttribute('data-api-base');
        var curated = {};
        try { curated = JSON.parse(panel.getAttribute('data-models') || '{}'); } catch (e) { curated = {}; }

        var providerEl = panel.querySelector('[data-ai-provider]');
        var modelEl    = panel.querySelector('[data-ai-model]');
        var menuEl     = panel.querySelector('[data-ai-model-menu]');
        var modelHint  = panel.querySelector('[data-ai-model-hint]');
        var keyEl      = panel.querySelector('[data-ai-key]');
        var keyHint    = panel.querySelector('[data-ai-key-hint]');
        // The server-rendered help, so switching away from Custom can put it back.
        var keyHintDefault = keyHint ? keyHint.textContent : '';
        var noteEl     = panel.querySelector('[data-ai-openrouter-note]');
        // Azure OpenAI deployment-based endpoints (discussion #86).
        var azureBox   = panel.querySelector('[data-ai-azure]');
        var azEndpoint = panel.querySelector('[data-ai-azure-endpoint]');
        var azDeploy   = panel.querySelector('[data-ai-azure-deployment]');
        var azVersion  = panel.querySelector('[data-ai-azure-api-version]');
        // A custom OpenAI-compatible server: local runner or self-hosted API.
        var customBox  = panel.querySelector('[data-ai-custom]');
        var customUrl = panel.querySelector('[data-ai-custom-base-url]');
        var modelGroup = panel.querySelector('[data-ai-model-group]');
        var saveBtn    = panel.querySelector('[data-ai-save]');
        var testBtn    = panel.querySelector('[data-ai-test]');
        var resultEl   = panel.querySelector('[data-ai-result]');

        var defaults = { anthropic: 'claude-haiku-4-5-20251001', openai: 'gpt-4o', openrouter: '', custom: '' };
        var orModelsLoaded = false;
        var MODELS = [];   // [{ id, price? }]  current suggestion set
        var menuOpen = false;

        /** Shows a result line under the panel's buttons, styled by cls (for example 'err'). */
        function setResult(text, cls) {
            resultEl.textContent = text || '';
            resultEl.className = 'ai-result' + (cls ? ' ' + cls : '');
        }

        // ---- custom model dropdown ----
        /**
         * Fills the model dropdown with up to 50 suggestions matching what has been
         * typed, with prices for OpenRouter models.
         */
        function renderMenu() {
            var q = (modelEl.value || '').trim().toLowerCase();
            var matches = MODELS.filter(function (m) {
                return !q || m.id.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 50); // cap so the menu stays short
            if (!matches.length) {
                menuEl.innerHTML = '<div class="ai-model-empty">' +
                    esc(tr('no_models', 'No matching models — you can type any model id')) + '</div>';
                return;
            }
            menuEl.innerHTML = matches.map(function (m) {
                var px = m.price ? '<span class="ai-model-px">' + esc(m.price) + '</span>' : '';
                return '<div class="ai-model-opt" data-id="' + esc(m.id) + '">' +
                       '<span class="ai-model-id">' + esc(m.id) + '</span>' + px + '</div>';
            }).join('');
        }
        /** Opens the model dropdown. */
        function showMenu() { renderMenu(); menuEl.hidden = false; menuOpen = true; }
        /** Closes the model dropdown. */
        function hideMenu() { menuEl.hidden = true; menuOpen = false; }

        modelEl.addEventListener('focus', showMenu);
        modelEl.addEventListener('input', function () { menuOpen ? renderMenu() : showMenu(); });
        modelEl.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
        modelEl.addEventListener('blur', function () { setTimeout(hideMenu, 150); });
        // mousedown (not click) so we win the race against the input's blur.
        menuEl.addEventListener('mousedown', function (e) {
            var opt = e.target.closest ? e.target.closest('.ai-model-opt') : null;
            if (!opt) return;
            e.preventDefault();
            modelEl.value = opt.getAttribute('data-id');
            hideMenu();
        });

        /** Uses the built-in model list for a provider as the dropdown's suggestions. */
        function setCuratedModels(provider) {
            MODELS = (curated[provider] || []).map(function (id) { return { id: id }; });
            modelHint.textContent = '';
        }

        /**
         * Fetches the live OpenRouter model catalogue with prices and uses it as the
         * dropdown's suggestions.
         */
        function loadOpenRouterModels() {
            modelHint.textContent = tr('loading_models', 'Loading model list…');
            fetch(apiBase + 'openrouter_models.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { modelHint.textContent = d.error || 'Could not load models'; return; }
                    orModelsLoaded = true;
                    MODELS = d.models.map(function (m) {
                        var inp = fmtPrice(m.prompt_price), out = fmtPrice(m.completion_price);
                        return { id: m.id, price: (inp && out) ? (inp + ' / ' + out) : '' };
                    });
                    modelHint.textContent = tr('openrouter_pricing', 'Prices shown per 1M tokens (in / out).')
                        + (d.stale ? ' (' + tr('models_stale', 'cached') + ')' : '');
                    if (menuOpen) renderMenu();
                })
                .catch(function () { modelHint.textContent = 'Could not load models'; });
        }

        /**
         * Shows the fields a provider needs - the Azure boxes instead of the model for
         * Azure OpenAI, the OpenRouter note and live catalogue for OpenRouter - and
         * resets the model to that provider's default unless opts.keepModel.
         */
        function applyProviderUi(provider, opts) {
            opts = opts || {};
            noteEl.classList.toggle('show', provider === 'openrouter');

            /* Azure asks a different question, so it shows different boxes. The
               Model field is HIDDEN rather than left alongside: on a
               deployment-based endpoint the deployment already decides the
               model, and showing both would be asking the same thing twice and
               inviting somebody to fill in a box that is never sent. */
            var isAzure = provider === 'azure';
            var isCustom = provider === 'custom';
            if (azureBox)   azureBox.hidden = !isAzure;
            if (customBox) customBox.hidden = !isCustom;
            if (modelGroup) modelGroup.hidden = isAzure;

            if (isAzure) {
                setCuratedModels(provider);   // empties the suggestion list
                return;                       // no model default to apply below
            }
            if (provider === 'openrouter') {
                if (!orModelsLoaded) loadOpenRouterModels(); else if (menuOpen) renderMenu();
            } else if (provider === 'custom') {
                // No catalogue to fetch: the server's own model list is the
                // only authority, so say where the name has to come from.
                setCuratedModels(provider);
                modelHint.textContent = tr('custom_model_hint',
                    'Type the model exactly as the server lists it, e.g. llama3.1 or mistral-small-latest.');
            } else {
                setCuratedModels(provider);
            }
            // On a provider switch, reset the model to that provider's default so
            // an OpenRouter id never gets sent to Anthropic (and vice versa). On
            // the initial hydrate we keep the saved model.
            if (!opts.keepModel) modelEl.value = defaults[provider] || '';
        }

        // ---- hydrate from saved settings ----
        fetch(apiBase + 'get_settings.php?ns=' + encodeURIComponent(ns), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { setResult(d.error || '', 'err'); return; }
                providerEl.value = d.provider || 'anthropic';
                modelEl.value = d.model || '';
                // Shown in the clear on purpose — an endpoint you cannot read
                // back is an endpoint whose typo you cannot find.
                if (azEndpoint) azEndpoint.value = d.azure_endpoint || '';
                if (azDeploy)   azDeploy.value   = d.azure_deployment || '';
                if (azVersion)  azVersion.value  = d.azure_api_version || '';
                // Shown in the clear too — an address you cannot read back is an
                // address whose typo you cannot find.
                if (customUrl) customUrl.value = d.custom_base_url || '';
                if (d.has_key) {
                    keyEl.placeholder = d.masked_key || '••••';
                    keyHint.textContent = tr('api_key_set', 'A key is saved. Leave blank to keep it.');
                }
                applyProviderUi(providerEl.value, { keepModel: true });
            })
            .catch(function () { applyProviderUi(providerEl.value || 'anthropic', { keepModel: true }); });

        providerEl.addEventListener('change', function () {
            applyProviderUi(providerEl.value, { keepModel: false });
            // Only on a SWITCH: the initial hydrate has already said whether a
            // key is saved, and must not be talked over. Custom is the one
            // provider whose key is optional, so it is the one that says so.
            keyHint.textContent = providerEl.value === 'custom'
                ? tr('api_key_optional_help',
                    'Optional. Leave blank for a server with no key (most local runners); sent as a Bearer token when filled.')
                : keyHintDefault;
        });

        // ---- save ----
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true;
            setResult('', '');
            var payload = {
                ns: ns,
                provider: providerEl.value,
                model: modelEl.value.trim(),
                api_key: keyEl.value, // blank → server keeps existing key
                // Always sent, whatever the provider — so switching to OpenAI
                // and back does not quietly empty an endpoint somebody spent
                // time getting right. The provider row decides what is used.
                azure_endpoint:    azEndpoint ? azEndpoint.value.trim() : '',
                azure_deployment:  azDeploy   ? azDeploy.value.trim()   : '',
                azure_api_version: azVersion  ? azVersion.value.trim()  : '',
                // Sent on the same terms as the Azure fields: always, whatever
                // the provider, so switching away and back cannot empty an
                // address somebody spent time getting right.
                custom_base_url:   customUrl  ? customUrl.value.trim()  : ''
            };
            fetch(apiBase + 'save_settings.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        keyEl.value = '';
                        if (payload.api_key) {
                            keyEl.placeholder = '••••';
                            keyHint.textContent = tr('api_key_set', 'A key is saved. Leave blank to keep it.');
                        }
                        toast(tr('saved', 'Saved'), 'success');
                    } else {
                        toast(d.error || tr('save_failed', 'Failed to save'), 'error');
                    }
                })
                .catch(function () { toast(tr('save_failed', 'Failed to save'), 'error'); })
                .finally(function () { saveBtn.disabled = false; });
        });

        // ---- test ----
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            setResult(tr('testing', 'Testing…'), 'busy');
            fetch(apiBase + 'test_connection.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ns: ns })
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        setResult(tr('test_ok', 'Connection OK') + ' — ' + d.provider + ' · ' + d.model + ' · ' + d.duration_ms + 'ms', 'ok');
                    } else {
                        setResult((tr('test_failed', 'Test failed') + ': ') + (d.error || ''), 'err');
                        // GH #171: what it most likely means, and where to see the whole exchange.
                        if (d.hint) {
                            var hint = document.createElement('div');
                            hint.className = 'ai-result-hint';
                            hint.textContent = d.hint;
                            resultEl.appendChild(hint);
                        }
                        if (d.debug_url) {
                            var more = document.createElement('div');
                            more.className = 'ai-result-hint';
                            var a = document.createElement('a');
                            a.href = d.debug_url;
                            a.target = '_blank';
                            a.rel = 'noopener';
                            a.textContent = tr('debug_link', 'See exactly what was sent and what came back (debug tool D014)');
                            more.appendChild(a);
                            resultEl.appendChild(more);
                        }
                    }
                })
                .catch(function () { setResult(tr('test_failed', 'Test failed'), 'err'); })
                .finally(function () { testBtn.disabled = false; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-ai-panel]').forEach(initPanel);
    });
})();
