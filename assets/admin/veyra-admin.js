(function (global) {
    'use strict';

    const PRODUCTS = new Set(['agent', 'knowledge', 'experience', 'commerce', 'operations']);
    const ACTIONS = new Set(['save_draft', 'validate', 'simulate', 'publish', 'schedule', 'rollback', 'import']);
    const OPERATION_STATES = new Set(['succeeded', 'failed', 'partial', 'uncertain', 'blocked', 'stale']);
    const RETRY_SAFETY = new Set(['safe_no_side_effect', 'reconcile_before_retry', 'never_retry']);
    const REQUIRED_TRUTHS = [
        'product_identity', 'variation', 'current_price', 'current_total', 'shipping', 'tax', 'fees',
        'required_terms', 'ai_identity', 'error_state', 'permission_state', 'confirmation_scope',
        'payment_implications', 'accessibility_controls'
    ];

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function isOpaqueId(value) {
        return typeof value === 'string' && value.length > 0 && value.length <= 128 && /^[A-Za-z0-9][A-Za-z0-9._:-]*$/.test(value);
    }

    function validateOperationEnvelope(envelope) {
        if (!isObject(envelope) || envelope.schema_version !== 'veyra.operation.v1') {
            return { valid: false, reason: 'schema' };
        }
        if (!OPERATION_STATES.has(envelope.status) || typeof envelope.code !== 'string' || envelope.code.length === 0) {
            return { valid: false, reason: 'result' };
        }
        if (!RETRY_SAFETY.has(envelope.retry_safety) || !isOpaqueId(envelope.correlation_id)) {
            return { valid: false, reason: 'safety' };
        }
        return { valid: true };
    }

    function validateAdminState(state, product) {
        if (!isObject(state) || state.schema_version !== 'veyra.admin_product_state.v1' || state.product !== product) {
            return { valid: false, reason: 'identity' };
        }
        if (!isObject(state.permissions) || !Array.isArray(state.available_actions)) {
            return { valid: false, reason: 'permissions' };
        }
        if (state.available_actions.some((action) => !ACTIONS.has(action))) {
            return { valid: false, reason: 'action' };
        }
        if (product !== 'operations') {
            if (!isObject(state.draft) || !isOpaqueId(state.draft.version) || !isObject(state.draft.configuration)) {
                return { valid: false, reason: 'draft' };
            }
            if (state.published !== null && state.published !== undefined &&
                (!isObject(state.published) || !isOpaqueId(state.published.version))) {
                return { valid: false, reason: 'published' };
            }
        }
        return { valid: true };
    }

    function evaluateTruthConfiguration(configuration) {
        const issues = [];
        if (!isObject(configuration)) {
            return [{ code: 'configuration_invalid', path: '$', message: 'Configuration must be an object.' }];
        }
        if (configuration.schema_version !== 'veyra.experience.v1') {
            issues.push({ code: 'schema_version_invalid', path: '$.schema_version' });
        }
        const hidden = Array.isArray(configuration.hidden_parts) ? configuration.hidden_parts : [];
        if (configuration.hidden_parts !== undefined && !Array.isArray(configuration.hidden_parts)) {
            issues.push({ code: 'hidden_parts_invalid', path: '$.hidden_parts' });
        }
        for (const truth of REQUIRED_TRUTHS) {
            if (hidden.includes(truth)) {
                issues.push({ code: 'mandatory_truth_hidden', path: '$.hidden_parts', truth });
            }
            const component = isObject(configuration.components) ? configuration.components[truth] : null;
            if (isObject(component) && Object.prototype.hasOwnProperty.call(component, 'visible') && component.visible !== true) {
                issues.push({ code: 'mandatory_truth_hidden', path: '$.components.' + truth + '.visible', truth });
            }
        }
        const tokens = isObject(configuration.tokens) ? configuration.tokens : {};
        if (tokens.minimum_touch_target_px !== undefined &&
            (!Number.isInteger(tokens.minimum_touch_target_px) || tokens.minimum_touch_target_px < 44 || tokens.minimum_touch_target_px > 80)) {
            issues.push({ code: 'touch_target_invalid', path: '$.tokens.minimum_touch_target_px' });
        }
        if (tokens.body_font_size_px !== undefined &&
            (!Number.isInteger(tokens.body_font_size_px) || tokens.body_font_size_px < 14 || tokens.body_font_size_px > 24)) {
            issues.push({ code: 'font_size_invalid', path: '$.tokens.body_font_size_px' });
        }
        if (tokens.focus_width_px !== undefined &&
            (!Number.isInteger(tokens.focus_width_px) || tokens.focus_width_px < 2 || tokens.focus_width_px > 8)) {
            issues.push({ code: 'focus_indicator_invalid', path: '$.tokens.focus_width_px' });
        }
        if (isObject(tokens.colors)) {
            for (const [name, color] of Object.entries(tokens.colors)) {
                if (typeof color !== 'string' || !/^#[0-9A-Fa-f]{6}$/.test(color)) {
                    issues.push({ code: 'color_invalid', path: '$.tokens.colors.' + name });
                }
            }
        }
        if (isObject(tokens.motion) && tokens.motion.honor_reduced_motion === false) {
            issues.push({ code: 'reduced_motion_required', path: '$.tokens.motion.honor_reduced_motion' });
        }
        return issues;
    }

    function safeRoute(restBase, route, product, origin) {
        if (typeof restBase !== 'string' || typeof route !== 'string' || !PRODUCTS.has(product)) {
            return null;
        }
        try {
            const pageOrigin = new URL(origin).origin;
            const base = new URL(restBase.replace(/\/?$/, '/'), pageOrigin);
            const expanded = route.replace('{product}', encodeURIComponent(product)).replace(/^\//, '');
            const candidate = new URL(expanded, base);
            if (base.origin !== pageOrigin || candidate.origin !== pageOrigin || !candidate.pathname.startsWith(base.pathname)) {
                return null;
            }
            return candidate.href;
        } catch (error) {
            return null;
        }
    }

    function operationValue(envelope) {
        if (Object.prototype.hasOwnProperty.call(envelope, 'value') && envelope.value !== null && envelope.value !== undefined) {
            return envelope.value;
        }
        if (Object.prototype.hasOwnProperty.call(envelope, 'safe_customer_message_data')) {
            return envelope.safe_customer_message_data;
        }
        return null;
    }

    function deepGet(object, path) {
        return path.split('.').reduce((value, segment) => isObject(value) ? value[segment] : undefined, object);
    }

    function featureConfiguredState(draftConfiguration, feature) {
        if (!isObject(feature) || typeof feature.key !== 'string') return 'Off';
        const draftState = deepGet(draftConfiguration, 'features.' + feature.key + '.configured_state');
        if (draftState === 'On' || draftState === 'Off') return draftState;
        return feature.configured_state === 'On' ? 'On' : 'Off';
    }

    function featureAccessibleLabel(feature) {
        const identity = isObject(feature) && typeof feature.label === 'string' && feature.label.trim() !== ''
            ? feature.label.trim()
            : (isObject(feature) && typeof feature.key === 'string' ? feature.key : 'feature');
        return 'Configured state for ' + identity;
    }

    function deepSet(object, path, value) {
        const segments = path.split('.');
        let cursor = object;
        segments.forEach((segment, index) => {
            if (index === segments.length - 1) {
                cursor[segment] = value;
                return;
            }
            if (!isObject(cursor[segment])) {
                cursor[segment] = {};
            }
            cursor = cursor[segment];
        });
        return object;
    }

    function createElement(tag, className, text) {
        const element = global.document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        return element;
    }

    class RequestFailure extends Error {
        constructor(message, status, envelope) {
            super(message);
            this.status = status;
            this.envelope = envelope || null;
        }
    }

    class AdminProduct {
        constructor(root, configuration) {
            this.root = root;
            this.configuration = configuration;
            this.strings = configuration.strings || {};
            this.product = root.dataset.product;
            this.form = root.querySelector('[data-admin-form]');
            this.live = root.querySelector('[data-admin-live]');
            this.error = root.querySelector('[data-admin-error]');
            this.stateBadge = root.querySelector('[data-admin-state]');
            this.version = root.querySelector('[data-admin-version]');
            this.dirtyLabel = root.querySelector('[data-dirty-state]');
            this.confirmDialog = root.querySelector('[data-admin-confirm]');
            this.confirmTitle = root.querySelector('[data-admin-confirm-title]');
            this.confirmCopy = root.querySelector('[data-admin-confirm-copy]');
            this.scheduleField = root.querySelector('[data-admin-schedule-field]');
            this.scheduleAt = root.querySelector('[data-admin-schedule-at]');
            this.loadedState = null;
            this.draftConfiguration = {};
            this.dirty = false;
            this.busy = false;
            this.pendingAction = null;
        }

        boot() {
            if (!PRODUCTS.has(this.product)) {
                return;
            }
            this.bindEvents();
            this.loadState();
        }

        bindEvents() {
            this.form?.addEventListener('input', () => this.markDirty());
            this.form?.addEventListener('change', () => this.markDirty());
            this.root.querySelectorAll('[data-admin-action]').forEach((button) => {
                button.addEventListener('click', () => this.prepareAction(button.dataset.adminAction));
            });
            this.root.querySelector('[data-admin-refresh]')?.addEventListener('click', () => this.loadState());
            this.confirmDialog?.addEventListener('close', () => {
                const action = this.pendingAction;
                this.pendingAction = null;
                if (this.confirmDialog.returnValue === 'confirm' && action) {
                    if (action.startsWith('provider:')) this.runProviderAction(action.slice(9));
                    else this.runAction(action);
                }
            });
            this.root.querySelectorAll('[data-provider-action]').forEach((button) => {
                button.addEventListener('click', () => this.prepareProviderAction(button.dataset.providerAction));
            });
            global.addEventListener('beforeunload', (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        }

        async loadState() {
            if (this.busy) return false;
            this.setBusy(true, this.strings.loading || 'Loading authoritative state…');
            try {
                const envelope = await this.request('state', 'GET');
                if (envelope.status !== 'succeeded') {
                    this.showOperationFailure(envelope);
                    return false;
                }
                const value = operationValue(envelope);
                const state = isObject(value) && isObject(value.state) ? value.state : value;
                const validation = validateAdminState(state, this.product);
                if (!validation.valid) {
                    throw new RequestFailure('Admin state did not match the public contract.', 502, envelope);
                }
                this.loadedState = JSON.parse(JSON.stringify(state));
                this.draftConfiguration = this.product === 'operations'
                    ? {}
                    : JSON.parse(JSON.stringify(state.draft.configuration));
                this.hydrate();
                this.setDirty(false);
                this.error.hidden = true;
                this.error.textContent = '';
                this.live.textContent = 'Authoritative state loaded. No action was performed.';
                return true;
            } catch (error) {
                this.showFailure(error);
                return false;
            } finally {
                this.setBusy(false);
            }
        }

        hydrate() {
            const state = this.loadedState;
            if (!state) return;
            this.stateBadge.textContent = state.effective_state || state.status || (this.product === 'operations' ? 'Read only' : 'Draft');
            this.stateBadge.dataset.tone = this.tone(this.stateBadge.textContent);
            this.version.textContent = state.published?.version || state.snapshot_version || 'No published version';

            if (this.form) {
                this.form.querySelectorAll('[data-field]').forEach((input) => {
                    const value = deepGet(this.draftConfiguration, input.dataset.field);
                    if (input.type === 'checkbox') {
                        input.checked = value === true;
                    } else if (value !== undefined && value !== null) {
                        input.value = String(value);
                    } else {
                        input.value = '';
                    }
                });
                const editable = state.permissions.edit === true;
                this.form.querySelectorAll('[data-admin-fieldset]').forEach((fieldset) => { fieldset.disabled = !editable; });
            }

            this.root.querySelector('[data-draft-version]')?.replaceChildren(global.document.createTextNode(state.draft?.version || 'Not available'));
            this.root.querySelector('[data-published-version]')?.replaceChildren(global.document.createTextNode(state.published?.version || 'Not published'));
            this.root.querySelector('[data-validation-state]')?.replaceChildren(global.document.createTextNode(state.validation?.status || 'Not run'));
            this.root.querySelector('[data-schedule-state]')?.replaceChildren(global.document.createTextNode(state.schedule?.activation_at || 'None'));
            this.renderResources(state.resources || {});
            this.updateActions();
        }

        renderResources(resources) {
            if (this.product === 'agent') this.renderProvider(resources.provider || {});
            if (this.product === 'knowledge') this.renderKnowledge(resources.knowledge_sources || []);
            if (this.product === 'experience') this.renderFixtures(resources.fixtures || []);
            if (this.product === 'commerce') this.renderFeatures(resources.features || []);
            if (this.product === 'operations') this.renderOperations(resources);
        }

        renderProvider(provider) {
            const health = this.root.querySelector('[data-provider-health]');
            if (health) {
                health.textContent = provider.readiness || 'Unavailable';
                health.dataset.tone = this.tone(health.textContent);
            }
            this.root.querySelectorAll('[data-provider]').forEach((element) => {
                const value = provider[element.dataset.provider];
                element.textContent = value === null || value === undefined || value === '' ? 'Not available' : String(value);
            });
            const canManageModels = this.loadedState?.permissions?.manage_models === true;
            this.root.querySelectorAll('[data-provider-action]').forEach((button) => {
                button.disabled = this.busy || !canManageModels;
            });
            const secret = this.root.querySelector('[data-provider-secret]');
            if (secret) {
                secret.value = '';
                secret.disabled = !canManageModels;
            }
        }

        prepareProviderAction(action) {
            if (!['save', 'clear', 'readiness'].includes(action) || this.busy ||
                this.product !== 'agent' || this.loadedState?.permissions?.manage_models !== true) {
                return;
            }
            if (action === 'clear') {
                this.pendingAction = 'provider:clear';
                this.confirmTitle.textContent = 'Clear Gemini credential';
                this.confirmCopy.textContent = 'The server will remove the protected credential reference and block affected routes. No credential has been cleared yet.';
                this.scheduleField.hidden = true;
                this.showDialog(this.confirmDialog);
                return;
            }
            this.runProviderAction(action);
        }

        async runProviderAction(action) {
            if (!['save', 'clear', 'readiness'].includes(action) || this.busy ||
                this.product !== 'agent' || this.loadedState?.permissions?.manage_models !== true) {
                return;
            }
            const secretInput = this.root.querySelector('[data-provider-secret]');
            let body;
            let route;
            let method;
            if (action === 'save') {
                const credential = secretInput ? secretInput.value.trim() : '';
                if (credential.length < 8 || credential.length > 4096) {
                    this.showError('Enter the Gemini credential before saving. Nothing was sent.');
                    return;
                }
                body = {
                    schema_version: 'veyra.provider_credential_command.v1',
                    provider: 'gemini',
                    api_key: credential
                };
                if (secretInput) secretInput.value = '';
                route = 'provider_credential';
                method = 'POST';
            } else if (action === 'clear') {
                body = {
                    schema_version: 'veyra.provider_credential_command.v1',
                    provider: 'gemini'
                };
                route = 'provider_credential';
                method = 'DELETE';
            } else {
                body = {
                    schema_version: 'veyra.provider_readiness_command.v1',
                    provider: 'gemini'
                };
                route = 'provider_readiness';
                method = 'POST';
            }

            this.setBusy(true, action === 'readiness' ? 'Running the explicit provider readiness test…' : 'Requesting protected credential update…');
            try {
                const envelope = await this.request(route, method, body, this.newOpaqueId('provider'));
                if (secretInput) secretInput.value = '';
                if (envelope.status !== 'succeeded') {
                    this.showOperationFailure(envelope);
                    await this.reconcileAdminState(this.error.textContent);
                    return;
                }
                const refreshed = await this.loadStateAfterBusy();
                if (!refreshed) {
                    this.showError('The provider command was accepted, but refreshed authoritative provider state could not be verified. Do not assume readiness or credential state.');
                    return;
                }
                this.live.textContent = (action === 'readiness'
                    ? 'Server-confirmed readiness state loaded.'
                    : 'Server-confirmed credential state loaded.') + ' Correlation: ' + envelope.correlation_id;
            } catch (error) {
                if (secretInput) secretInput.value = '';
                this.showFailure(error);
                await this.reconcileAdminState(this.error.textContent);
            } finally {
                this.setBusy(false);
                this.renderProvider(this.loadedState?.resources?.provider || {});
            }
        }

        renderKnowledge(sources) {
            const container = this.root.querySelector('[data-knowledge-sources]');
            if (!container) return;
            container.replaceChildren();
            if (!Array.isArray(sources) || sources.length === 0) {
                container.appendChild(createElement('p', null, 'No approved source state is available.'));
                return;
            }
            const list = createElement('ul');
            for (const source of sources) {
                if (!isObject(source)) continue;
                const item = createElement('li');
                item.appendChild(createElement('strong', null, source.title || source.source_id || 'Source'));
                item.appendChild(global.document.createTextNode(' — ' + (source.status || 'unknown') + (source.version ? ' · ' + source.version : '')));
                list.appendChild(item);
            }
            container.appendChild(list);
            const addButton = this.root.querySelector('[data-admin-resource-action="new_source"]');
            if (addButton) addButton.disabled = !this.loadedState.available_actions.includes('save_draft');
        }

        renderFixtures(fixtures) {
            const container = this.root.querySelector('[data-experience-fixtures]');
            if (!container) return;
            container.replaceChildren();
            if (!Array.isArray(fixtures) || fixtures.length === 0) {
                container.appendChild(createElement('p', null, 'No validated fixture result is available.'));
                return;
            }
            for (const fixture of fixtures) {
                if (!isObject(fixture)) continue;
                const card = createElement('article', 'veyra-admin__health-card');
                card.appendChild(createElement('h3', null, fixture.label || fixture.fixture_id || 'Fixture'));
                card.appendChild(createElement('p', null, fixture.status || 'unknown'));
                container.appendChild(card);
            }
        }

        renderFeatures(features) {
            const body = this.root.querySelector('[data-feature-rows]');
            if (!body) return;
            body.replaceChildren();
            if (!Array.isArray(features) || features.length === 0) {
                const row = createElement('tr');
                const cell = createElement('td', null, 'No authoritative feature state is available.');
                cell.colSpan = 4;
                row.appendChild(cell);
                body.appendChild(row);
                return;
            }
            for (const feature of features) {
                if (!isObject(feature) || typeof feature.key !== 'string') continue;
                const configuredState = featureConfiguredState(this.draftConfiguration, feature);
                const row = createElement('tr');
                const name = createElement('th', null, feature.label || feature.key);
                name.scope = 'row';
                const configured = createElement('td');
                const select = createElement('select');
                select.dataset.field = 'features.' + feature.key + '.configured_state';
                select.setAttribute('aria-label', featureAccessibleLabel(feature));
                for (const optionValue of ['On', 'Off']) {
                    const option = createElement('option', null, optionValue);
                    option.value = optionValue;
                    option.selected = configuredState === optionValue;
                    select.appendChild(option);
                }
                select.disabled = this.loadedState.permissions.edit !== true;
                select.addEventListener('change', () => this.markDirty());
                configured.appendChild(select);
                const effective = createElement('td');
                const badge = createElement('span', 'veyra-admin__state', feature.effective_state || 'Unknown');
                badge.dataset.tone = this.tone(feature.effective_state || 'Unknown');
                effective.appendChild(badge);
                const reason = createElement('td', null, [feature.reason, feature.remediation].filter(Boolean).join(' — ') || 'No reason supplied.');
                row.append(name, configured, effective, reason);
                body.appendChild(row);
            }
        }

        renderOperations(resources) {
            const healthGrid = this.root.querySelector('[data-health-grid]');
            if (healthGrid && Array.isArray(resources.health)) {
                healthGrid.replaceChildren();
                for (const health of resources.health) {
                    if (!isObject(health)) continue;
                    const card = createElement('article', 'veyra-admin__health-card');
                    card.appendChild(createElement('h2', null, health.label || health.key || 'Service'));
                    const badge = createElement('span', 'veyra-admin__state', health.state || 'Unknown');
                    badge.dataset.tone = this.tone(health.state || 'Unknown');
                    card.appendChild(badge);
                    if (health.safe_reason) card.appendChild(createElement('p', null, health.safe_reason));
                    healthGrid.appendChild(card);
                }
            }
            for (const name of ['conversations', 'crm', 'payment_reviews', 'failures']) {
                const container = this.root.querySelector('[data-operation-resource="' + name + '"]');
                if (!container) continue;
                container.replaceChildren();
                const records = resources[name];
                if (!Array.isArray(records) || records.length === 0) {
                    container.appendChild(createElement('p', null, 'No records in the loaded page.'));
                    continue;
                }
                const list = createElement('ul');
                for (const record of records.slice(0, 20)) {
                    if (!isObject(record)) continue;
                    list.appendChild(createElement('li', null, [record.label || record.reference || 'Record', record.status].filter(Boolean).join(' — ')));
                }
                container.appendChild(list);
            }
        }

        markDirty() {
            if (!this.loadedState || this.product === 'operations') return;
            this.setDirty(true);
            this.updateActions();
        }

        setDirty(dirty) {
            this.dirty = dirty;
            if (!this.dirtyLabel) return;
            this.dirtyLabel.dataset.dirty = dirty ? 'true' : 'false';
            this.dirtyLabel.textContent = dirty
                ? (this.strings.dirty || 'Unsaved draft changes')
                : (this.strings.clean || 'Draft matches the loaded server version');
        }

        collectConfiguration() {
            const output = JSON.parse(JSON.stringify(this.draftConfiguration || {}));
            this.form?.querySelectorAll('[data-field]').forEach((input) => {
                let value;
                if (input.type === 'checkbox') value = input.checked;
                else if (input.type === 'number') value = input.value === '' ? null : Number(input.value);
                else value = input.value;
                deepSet(output, input.dataset.field, value);
            });
            return output;
        }

        prepareAction(action) {
            if (!ACTIONS.has(action) || this.busy || !this.loadedState?.available_actions.includes(action)) {
                return;
            }
            if (['publish', 'schedule', 'rollback'].includes(action)) {
                this.pendingAction = action;
                this.confirmTitle.textContent = action === 'rollback' ? 'Confirm rollback' : action === 'schedule' ? 'Confirm scheduled activation' : 'Confirm publication';
                this.confirmCopy.textContent = action === 'rollback'
                    ? 'The server will validate and publish an eligible earlier immutable version. No rollback has occurred yet.'
                    : 'The server will recheck capability, schema, dependencies, conflicts, and publication state. No publication has occurred yet.';
                this.scheduleField.hidden = action !== 'schedule';
                this.showDialog(this.confirmDialog);
                return;
            }
            this.runAction(action);
        }

        async runAction(action) {
            if (!ACTIONS.has(action) || this.busy || !this.loadedState) return;
            const route = action === 'save_draft' ? 'draft' : action;
            const method = action === 'save_draft' ? 'PATCH' : 'POST';
            let configuration = null;
            if (this.product !== 'operations') {
                if (this.form && !this.form.checkValidity()) {
                    this.form.reportValidity();
                    return;
                }
                configuration = this.collectConfiguration();
                if (this.product === 'experience') {
                    const truthIssues = evaluateTruthConfiguration(configuration);
                    if (truthIssues.length > 0) {
                        this.showError('This draft attempts to hide or weaken mandatory truth or accessibility. Publication was not requested.');
                        return;
                    }
                }
            }
            const command = {
                schema_version: 'veyra.admin_product_command.v1',
                product: this.product,
                action,
                expected_draft_version: this.loadedState.draft?.version || null,
                expected_published_version: this.loadedState.published?.version || null,
                configuration,
                activation_at: action === 'schedule' && this.scheduleAt ? this.scheduleAt.value || null : null
            };
            this.setBusy(true, 'Requesting server-side ' + action.replace(/_/g, ' ') + '…');
            try {
                const envelope = await this.request(route, method, command, this.newOpaqueId('admin'));
                if (envelope.status !== 'succeeded') {
                    this.showOperationFailure(envelope);
                    await this.reconcileAdminState(this.error.textContent);
                    return;
                }
                const refreshed = await this.loadStateAfterBusy();
                if (!refreshed) {
                    this.showError('The server accepted the command, but refreshed authoritative state could not be verified. Do not assume publication or rollback succeeded.');
                    return;
                }
                const completedLabel = {
                    save_draft: this.strings.saved || 'Draft saved by the server.',
                    validate: this.strings.validated || 'Validation completed.',
                    publish: this.strings.published || 'Published version confirmed by the server.',
                    rollback: this.strings.rolled_back || 'Rollback confirmed by the server.',
                    simulate: 'Simulation result loaded from the server.',
                    schedule: 'Scheduled activation state confirmed by the server.'
                }[action] || 'Server state refreshed.';
                this.live.textContent = completedLabel + ' Correlation: ' + envelope.correlation_id;
            } catch (error) {
                this.showFailure(error);
                await this.reconcileAdminState(this.error.textContent);
            } finally {
                this.setBusy(false);
            }
        }

        async loadStateAfterBusy() {
            this.busy = false;
            const result = await this.loadState();
            this.busy = true;
            return result;
        }

        async reconcileAdminState(failureMessage) {
            const refreshed = await this.loadStateAfterBusy();
            const suffix = refreshed
                ? ' Authoritative state was refreshed; inspect it before attempting another write.'
                : ' Authoritative state could not be refreshed; do not retry the write.';
            this.showError((failureMessage || 'The command outcome was not confirmed.') + suffix);
            return refreshed;
        }

        updateActions() {
            const available = new Set(this.loadedState?.available_actions || []);
            this.root.querySelectorAll('[data-admin-action]').forEach((button) => {
                const action = button.dataset.adminAction;
                button.disabled = this.busy || !available.has(action) || (action === 'save_draft' && !this.dirty);
            });
        }

        async request(routeKey, method, body, idempotencyKey) {
            const route = this.configuration.routes?.[routeKey];
            const url = safeRoute(this.configuration.rest_base, route, this.product, global.location.origin);
            if (!url) throw new RequestFailure('The configured REST route is invalid.', 0, null);
            const controller = new AbortController();
            const timeout = global.setTimeout(() => controller.abort(), Number(this.configuration.request_timeout_ms || 90000));
            const headers = { 'Accept': 'application/json', 'X-Veyra-Correlation-ID': this.newOpaqueId('web') };
            if (this.configuration.nonce) headers['X-WP-Nonce'] = this.configuration.nonce;
            if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
            if (body !== undefined) headers['Content-Type'] = 'application/json';
            let response;
            try {
                response = await global.fetch(url, {
                    method,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    headers,
                    body: body === undefined ? undefined : JSON.stringify(body),
                    signal: controller.signal
                });
            } catch (error) {
                throw new RequestFailure(error?.name === 'AbortError' ? 'The request timed out. Outcome is not assumed.' : 'The server could not be reached. No outcome is assumed.', 0, null);
            } finally {
                global.clearTimeout(timeout);
            }
            let envelope;
            try {
                envelope = await response.json();
            } catch (error) {
                throw new RequestFailure('The response was not valid JSON.', response.status, null);
            }
            const validation = validateOperationEnvelope(envelope);
            if (!validation.valid) throw new RequestFailure('The response did not match the public operation contract.', response.status, null);
            if (!response.ok && envelope.status === 'succeeded') throw new RequestFailure('HTTP and operation states conflict.', response.status, envelope);
            return envelope;
        }

        showOperationFailure(envelope) {
            const value = operationValue(envelope);
            const message = isObject(value) && typeof value.message === 'string'
                ? value.message
                : (this.strings.unavailable || 'The authoritative service is unavailable. No change was assumed.');
            this.showError(message + ' [' + envelope.code + ' · ' + envelope.correlation_id + ']');
        }

        showFailure(error) {
            if (error instanceof RequestFailure && error.envelope) {
                this.showOperationFailure(error.envelope);
                return;
            }
            this.showError(error instanceof Error ? error.message : (this.strings.unavailable || 'The service is unavailable.'));
        }

        showError(message) {
            this.error.hidden = false;
            this.error.textContent = message;
            this.live.textContent = 'No success was recorded.';
        }

        setBusy(busy, message) {
            this.busy = busy;
            if (message) this.live.textContent = message;
            this.updateActions();
            const canManageModels = this.loadedState?.permissions?.manage_models === true;
            this.root.querySelectorAll('[data-provider-action]').forEach((button) => {
                button.disabled = busy || !canManageModels;
            });
            const providerSecret = this.root.querySelector('[data-provider-secret]');
            if (providerSecret) providerSecret.disabled = busy || !canManageModels;
            this.root.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        showDialog(dialog) {
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }

        tone(value) {
            const normalized = String(value).toLowerCase();
            if (['on', 'ready', 'published', 'healthy', 'succeeded'].some((state) => normalized.includes(state))) return 'ready';
            if (['blocked', 'failed', 'error', 'unhealthy'].some((state) => normalized.includes(state))) return 'blocked';
            if (['degraded', 'stale', 'pending', 'warning'].some((state) => normalized.includes(state))) return 'degraded';
            return 'neutral';
        }

        newOpaqueId(prefix) {
            if (global.crypto?.randomUUID) return prefix + ':' + global.crypto.randomUUID();
            const bytes = new Uint8Array(16);
            if (global.crypto?.getRandomValues) {
                global.crypto.getRandomValues(bytes);
                return prefix + ':' + Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
            }
            throw new RequestFailure('Secure browser randomness is unavailable. Nothing was sent.', 0, null);
        }
    }

    const contracts = Object.freeze({
        PRODUCTS,
        REQUIRED_TRUTHS,
        validateOperationEnvelope,
        validateAdminState,
        evaluateTruthConfiguration,
        safeRoute,
        deepGet,
        deepSet,
        featureConfiguredState,
        featureAccessibleLabel
    });

    global.VeyraAdminContracts = contracts;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = contracts;
    }

    function mount() {
        if (!global.document || !isObject(global.VeyraAdminBootstrap)) return;
        global.document.querySelectorAll('[data-veyra-admin-shell]').forEach((root) => {
            if (root.dataset.veyraMounted === 'true') return;
            root.dataset.veyraMounted = 'true';
            new AdminProduct(root, global.VeyraAdminBootstrap).boot();
        });
    }

    if (global.document) {
        if (global.document.readyState === 'loading') global.document.addEventListener('DOMContentLoaded', mount, { once: true });
        else mount();
    }
})(typeof window !== 'undefined' ? window : globalThis);
