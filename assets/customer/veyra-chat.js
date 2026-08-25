(function (global) {
    'use strict';

    const MESSAGE_SCHEMA = 'veyra.message.v1';
    const SENDERS = new Set(['shopper', 'ai', 'human', 'system']);
    const MESSAGE_STATES = new Set(['sending', 'processing', 'delivered', 'failed', 'retrying', 'cancelled']);
    const OPERATION_STATES = new Set(['succeeded', 'failed', 'partial', 'uncertain', 'blocked', 'stale']);
    const RETRY_SAFETY = new Set(['safe_no_side_effect', 'reconcile_before_retry', 'never_retry']);
    const COMPONENT_TYPES = new Set([
        'product', 'comparison', 'cart', 'checkout', 'order', 'crm_case',
        'payment_review', 'return', 'branch', 'hours', 'delivery', 'handoff', 'notice'
    ]);
    const MAX_PRODUCT_REFERENCES = 3;

    function isObject(value) {
        return value !== null && typeof value === 'object' && !Array.isArray(value);
    }

    function hasString(value, key) {
        return isObject(value) && typeof value[key] === 'string' && value[key].length > 0;
    }

    function isOpaqueId(value) {
        return typeof value === 'string' && value.length <= 128 && /^[A-Za-z0-9][A-Za-z0-9._:-]*$/.test(value);
    }

    function isAbsoluteTimestamp(value) {
        return typeof value === 'string' &&
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
            !Number.isNaN(Date.parse(value));
    }

    function validateProductReference(reference) {
        if (!isObject(reference) || reference.schema_version !== 'veyra.product_reference.v1' ||
            !isOpaqueId(reference.reference_id) || !isOpaqueId(reference.source_message_id) ||
            productReferenceSnapshotIdentity(reference.snapshot) === null) {
            return { valid: false, reason: 'product_reference_contract' };
        }
        if (reference.context_only !== true || reference.commerce_authorization !== false) {
            return { valid: false, reason: 'product_reference_authority' };
        }
        if (reference.current_state !== undefined && reference.current_state !== null && !isObject(reference.current_state)) {
            return { valid: false, reason: 'product_reference_current_state' };
        }
        return { valid: true };
    }

    function productReferenceSourceMessageId(reference) {
        return validateProductReference(reference).valid ? reference.source_message_id : null;
    }

    function productReferenceSnapshotIdentity(snapshot) {
        return isObject(snapshot) && Number.isSafeInteger(snapshot.product_id) && snapshot.product_id > 0 &&
            Number.isSafeInteger(snapshot.variation_id) && snapshot.variation_id >= 0
            ? { product_id: snapshot.product_id, variation_id: snapshot.variation_id }
            : null;
    }

    function normalizeProductReferenceBinding(value) {
        const keys = ['schema_version', 'reference_id', 'source_message_id', 'product_id', 'variation_id'];
        if (!isObject(value) || Object.keys(value).length !== keys.length ||
            !keys.every((key) => Object.prototype.hasOwnProperty.call(value, key)) ||
            value.schema_version !== 'veyra.product_reference_binding.v1' ||
            !isOpaqueId(value.reference_id) || !isOpaqueId(value.source_message_id) ||
            !Number.isSafeInteger(value.product_id) || value.product_id < 1 ||
            !Number.isSafeInteger(value.variation_id) || value.variation_id < 0) {
            return null;
        }
        return {
            schema_version: 'veyra.product_reference_binding.v1',
            reference_id: value.reference_id,
            source_message_id: value.source_message_id,
            product_id: value.product_id,
            variation_id: value.variation_id
        };
    }

    function productReferenceBinding(reference) {
        if (!validateProductReference(reference).valid) {
            return null;
        }
        const identity = productReferenceSnapshotIdentity(reference.snapshot);
        return normalizeProductReferenceBinding({
            schema_version: 'veyra.product_reference_binding.v1',
            reference_id: reference.reference_id,
            source_message_id: reference.source_message_id,
            product_id: identity.product_id,
            variation_id: identity.variation_id
        });
    }

    function productReferenceBindings(drafts) {
        if (!Array.isArray(drafts)) {
            return [];
        }
        const bindings = [];
        const seen = new Set();
        for (const draft of drafts) {
            const binding = normalizeProductReferenceBinding(isObject(draft) && isObject(draft.binding) ? draft.binding : draft);
            if (binding === null || seen.has(binding.reference_id)) {
                continue;
            }
            seen.add(binding.reference_id);
            bindings.push(binding);
            if (bindings.length === MAX_PRODUCT_REFERENCES) {
                break;
            }
        }
        return bindings;
    }

    function normalizeQuickReplies(replies) {
        if (!Array.isArray(replies)) {
            return [];
        }
        const normalized = [];
        const seen = new Set();
        for (const reply of replies.slice(0, 8)) {
            if (!isObject(reply)
                || !isOpaqueId(reply.choice_id)
                || !isOpaqueId(reply.pending_question_id)
                || typeof reply.label !== 'string'
            ) {
                continue;
            }
            const label = reply.label.trim();
            if (label === '' || label.length > 160 || seen.has(reply.choice_id)) {
                continue;
            }
            seen.add(reply.choice_id);
            normalized.push({
                choice_id: reply.choice_id,
                label,
                pending_question_id: reply.pending_question_id
            });
        }
        return normalized;
    }

    function validateOperationEnvelope(envelope) {
        if (!isObject(envelope)) {
            return { valid: false, reason: 'not_object' };
        }
        if (envelope.schema_version !== 'veyra.operation.v1') {
            return { valid: false, reason: 'schema_version' };
        }
        if (!OPERATION_STATES.has(envelope.status) || !hasString(envelope, 'code')) {
            return { valid: false, reason: 'status_or_code' };
        }
        if (!RETRY_SAFETY.has(envelope.retry_safety)) {
            return { valid: false, reason: 'retry_safety' };
        }
        if (!isOpaqueId(envelope.correlation_id)) {
            return { valid: false, reason: 'correlation_id' };
        }
        return { valid: true };
    }

    function validateMessagePayload(message) {
        if (!isObject(message) || message.schema_version !== MESSAGE_SCHEMA) {
            return { valid: false, reason: 'schema_version' };
        }
        if (!isOpaqueId(message.message_id) || !isOpaqueId(message.conversation_id)) {
            return { valid: false, reason: 'identity' };
        }
        if (!SENDERS.has(message.sender) || !MESSAGE_STATES.has(message.state)) {
            return { valid: false, reason: 'sender_or_state' };
        }
        if (!isOpaqueId(message.rendering_version) || !isAbsoluteTimestamp(message.created_at)) {
            return { valid: false, reason: 'version_or_time' };
        }
        if (!isObject(message.content) || typeof message.content.text !== 'string') {
            return { valid: false, reason: 'content' };
        }
        if (!['auto', 'ltr', 'rtl'].includes(message.content.direction) || typeof message.content.language !== 'string') {
            return { valid: false, reason: 'language_or_direction' };
        }
        const references = message.product_references || [];
        if (!Array.isArray(references) || references.length > MAX_PRODUCT_REFERENCES) {
            return { valid: false, reason: 'product_references' };
        }
        for (const reference of references) {
            const validation = validateProductReference(reference);
            if (!validation.valid) {
                return validation;
            }
        }
        const components = message.components || [];
        if (!Array.isArray(components) || components.length > 24) {
            return { valid: false, reason: 'components' };
        }
        for (const component of components) {
            if (!isObject(component) || component.schema_version !== 'veyra.component.v1' ||
                !isOpaqueId(component.component_id) || !COMPONENT_TYPES.has(component.type) || !isObject(component.snapshot)) {
                return { valid: false, reason: 'component_contract' };
            }
        }
        return { valid: true };
    }

    function validateAction(action) {
        if (!isObject(action) || !isOpaqueId(action.action_id) || typeof action.label !== 'string' || action.label.length === 0) {
            return { valid: false, reason: 'identity' };
        }
        if (!['compose', 'navigate', 'interaction'].includes(action.kind)) {
            return { valid: false, reason: 'kind' };
        }
        if (action.kind === 'compose' && typeof action.intent_text !== 'string') {
            return { valid: false, reason: 'intent_text' };
        }
        if (action.kind === 'navigate' && safeUrl(action.url, global.location ? global.location.origin : 'https://example.invalid') === null) {
            return { valid: false, reason: 'url' };
        }
        if (action.kind === 'interaction') {
            if (!isOpaqueId(action.idempotency_key)) {
                return { valid: false, reason: 'idempotency_key' };
            }
            if (action.requires_confirmation === true) {
                const required = ['confirmation_id', 'summary_message_id', 'state_hash', 'confirmation_summary'];
                if (action.summary_complete !== true || required.some((key) => !hasString(action, key)) || !hasString(action, 'expires_at')) {
                    return { valid: false, reason: 'confirmation_contract' };
                }
                if (!isAbsoluteTimestamp(action.expires_at)) {
                    return { valid: false, reason: 'confirmation_expiry' };
                }
            }
        }
        return { valid: true };
    }

    function safeUrl(value, baseOrigin) {
        if (typeof value !== 'string' || value.length === 0) {
            return null;
        }
        try {
            const parsed = new URL(value, baseOrigin);
            if (!['http:', 'https:'].includes(parsed.protocol)) {
                return null;
            }
            return parsed.href;
        } catch (error) {
            return null;
        }
    }

    function resolveApiUrl(restBase, route, origin) {
        if (typeof restBase !== 'string' || typeof route !== 'string') {
            return null;
        }
        try {
            const base = new URL(restBase.replace(/\/?$/, '/'), origin);
            const pageOrigin = new URL(origin).origin;
            const candidate = new URL(route.replace(/^\//, ''), base);
            if (base.origin !== pageOrigin || candidate.origin !== pageOrigin || !candidate.pathname.startsWith(base.pathname)) {
                return null;
            }
            return candidate;
        } catch (error) {
            return null;
        }
    }

    function deriveRetryPolicy(envelope) {
        const validation = validateOperationEnvelope(envelope);
        if (!validation.valid) {
            return 'reconcile';
        }
        if (envelope.retry_safety === 'safe_no_side_effect') {
            return 'manual_safe';
        }
        if (envelope.retry_safety === 'never_retry') {
            return 'never';
        }
        return 'reconcile';
    }

    function shouldReplaceHistorical(existing, incoming) {
        if (!existing) {
            return true;
        }
        if (!incoming || existing.message_id !== incoming.message_id) {
            return false;
        }
        return false;
    }

    function draftKey(actorScope, conversationId) {
        const scope = String(actorScope || 'guest').replace(/[^A-Za-z0-9._:-]/g, '_').slice(0, 96);
        const conversation = String(conversationId || 'current').replace(/[^A-Za-z0-9._:-]/g, '_').slice(0, 96);
        return 'veyra:draft:v1:' + scope + ':' + conversation;
    }

    function bindConversationId(currentId, candidateId) {
        if (!isOpaqueId(candidateId)) {
            return null;
        }
        if (currentId === null || currentId === undefined) {
            return candidateId;
        }
        return currentId === candidateId ? currentId : null;
    }

    function messagesBelongToConversation(messages, conversationId) {
        return isOpaqueId(conversationId)
            && Array.isArray(messages)
            && messages.every((message) => isObject(message) && message.conversation_id === conversationId);
    }

    function readGuestCsrf(cookieString) {
        if (typeof cookieString !== 'string') {
            return null;
        }
        for (const segment of cookieString.split(';')) {
            const separator = segment.indexOf('=');
            if (separator < 0 || segment.slice(0, separator).trim() !== 'veyra_guest_csrf') {
                continue;
            }
            let value = segment.slice(separator + 1).trim();
            try {
                value = decodeURIComponent(value);
            } catch (error) {
                return null;
            }
            if (value.length >= 32 && value.length <= 192 && /^[A-Za-z0-9_-]+$/.test(value)) {
                return value;
            }
            return null;
        }
        return null;
    }

    function requestSecurityHeaders(nonce, cookieString) {
        const headers = {};
        if (typeof nonce === 'string' && nonce.length >= 1 && nonce.length <= 192 && /^[A-Za-z0-9_-]+$/.test(nonce)) {
            headers['X-WP-Nonce'] = nonce;
            return headers;
        }
        const guestCsrf = readGuestCsrf(cookieString);
        if (guestCsrf) {
            headers['X-Veyra-CSRF'] = guestCsrf;
        }
        return headers;
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

    function extractReturnedMessages(value) {
        if (!isObject(value)) {
            return { valid: false, reason: 'value', messages: [] };
        }

        const candidates = [];
        if (Object.prototype.hasOwnProperty.call(value, 'message')) {
            if (!isObject(value.message)) {
                return { valid: false, reason: 'message', messages: [] };
            }
            candidates.push(value.message);
        }
        if (Object.prototype.hasOwnProperty.call(value, 'messages')) {
            if (!Array.isArray(value.messages)) {
                return { valid: false, reason: 'messages', messages: [] };
            }
            candidates.push(...value.messages);
        }
        if (candidates.length === 0) {
            return { valid: false, reason: 'missing', messages: [] };
        }

        const messages = [];
        const seen = new Map();
        for (const message of candidates) {
            const validation = validateMessagePayload(message);
            if (!validation.valid) {
                return { valid: false, reason: validation.reason, messages: [] };
            }
            if (seen.has(message.message_id)) {
                if (JSON.stringify(seen.get(message.message_id)) !== JSON.stringify(message)) {
                    return { valid: false, reason: 'conflicting_duplicate', messages: [] };
                }
                continue;
            }
            seen.set(message.message_id, message);
            messages.push(message);
        }

        return { valid: true, messages };
    }

    function createElement(tag, className, text) {
        const element = global.document.createElement(tag);
        if (className) {
            element.className = className;
        }
        if (text !== undefined && text !== null) {
            element.textContent = String(text);
        }
        return element;
    }

    function addBidiText(parent, value, direction) {
        const bdi = createElement('bdi', null, value);
        bdi.dir = direction || 'auto';
        parent.appendChild(bdi);
        return bdi;
    }

    function readableValue(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }
        if (Array.isArray(value)) {
            const items = value.filter((item) => ['string', 'number'].includes(typeof item)).map(String);
            return items.length > 0 ? items.join(' · ') : null;
        }
        if (isObject(value)) {
            for (const key of ['display', 'formatted', 'label', 'name']) {
                if (typeof value[key] === 'string' && value[key] !== '') {
                    return value[key];
                }
            }
            return null;
        }
        return String(value);
    }

    class RequestFailure extends Error {
        constructor(message, status, envelope, uncertain) {
            super(message);
            this.name = 'RequestFailure';
            this.status = status;
            this.envelope = envelope || null;
            this.uncertain = uncertain === true;
        }
    }

    class CustomerChat {
        constructor(root, configuration) {
            this.root = root;
            this.configuration = configuration;
            this.conversationId = isOpaqueId(configuration.conversation_id) ? configuration.conversation_id : null;
            this.strings = configuration.strings || {};
            this.surface = root.dataset.veyraSurface || 'launcher';
            this.panel = root.querySelector('[data-veyra-panel]');
            this.launcher = root.querySelector('[data-veyra-open]');
            this.timeline = root.querySelector('[data-veyra-timeline]');
            this.scroll = root.querySelector('[data-veyra-scroll]');
            this.empty = root.querySelector('[data-veyra-empty]');
            this.form = root.querySelector('[data-veyra-composer]');
            this.input = root.querySelector('[data-veyra-input]');
            this.sendButton = root.querySelector('[data-veyra-send]');
            this.stopButton = root.querySelector('[data-veyra-stop]');
            this.activity = root.querySelector('[data-veyra-activity]');
            this.error = root.querySelector('[data-veyra-error]');
            this.connection = root.querySelector('[data-veyra-connection]');
            this.quickReplies = root.querySelector('[data-veyra-quick-replies]');
            this.loadOlderButton = root.querySelector('[data-veyra-load-older]');
            this.jumpButton = root.querySelector('[data-veyra-jump]');
            this.draftContext = root.querySelector('[data-veyra-draft-context]');
            this.draftQuote = root.querySelector('[data-veyra-draft-quote]');
            this.draftQuoteText = root.querySelector('[data-veyra-draft-quote-text]');
            this.draftReferences = root.querySelector('[data-veyra-draft-references]');
            this.confirmDialog = root.querySelector('[data-veyra-confirm-dialog]');
            this.confirmSummary = root.querySelector('[data-veyra-confirm-summary]');
            this.confirmSubmit = root.querySelector('[data-veyra-confirm-submit]');
            this.messages = new Map();
            this.messageNodes = new Map();
            this.pendingCommands = new Map();
            this.acceptedPendingNodes = new Map();
            this.inFlightActions = new Set();
            this.replyDraft = null;
            this.productReferenceDrafts = [];
            this.historyCursor = null;
            this.historyLoaded = false;
            this.loadingHistory = false;
            this.postSendRefreshQueued = false;
            this.activeTurnId = null;
            this.previousFocus = null;
            this.inerted = [];
            this.sessionExpired = false;
            this.reconciliationRequired = false;
            this.reconciliationHandle = null;
            this.composing = false;
            this.pendingConfirmAction = null;
            this.boundTrap = (event) => this.trapFocus(event);
        }

        boot() {
            if (!this.panel || !this.form || !this.input || !this.timeline) {
                return false;
            }
            this.input.maxLength = Number(this.configuration.max_message_length || 4000);
            this.bindEvents();
            this.restoreDraft();
            this.updateComposerState();
            if (!this.panel.hidden) {
                this.activatePanel(false);
            }
            return true;
        }

        bindEvents() {
            this.launcher?.addEventListener('click', () => this.open());
            this.root.querySelector('[data-veyra-close]')?.addEventListener('click', () => this.close());
            this.root.querySelector('[data-veyra-history]')?.addEventListener('click', () => this.loadHistory(true));
            this.root.querySelector('[data-veyra-new]')?.addEventListener('click', () => this.startNewConversation());
            this.root.querySelector('[data-veyra-remove-quote]')?.addEventListener('click', () => this.setReplyDraft(null));
            this.form.addEventListener('submit', (event) => {
                event.preventDefault();
                this.submitDraft();
            });
            this.input.addEventListener('compositionstart', () => { this.composing = true; });
            this.input.addEventListener('compositionend', () => { this.composing = false; });
            this.input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey && !this.composing) {
                    event.preventDefault();
                    this.submitDraft();
                }
            });
            this.input.addEventListener('input', () => {
                this.autosizeComposer();
                this.persistDraft();
                this.updateComposerState();
            });
            this.stopButton?.addEventListener('click', () => this.stopResponse());
            this.loadOlderButton?.addEventListener('click', () => this.loadHistory(false));
            this.jumpButton?.addEventListener('click', () => this.scrollToLatest(true));
            this.scroll?.addEventListener('scroll', () => this.updateJumpButton(), { passive: true });
            global.addEventListener('offline', () => this.showOffline());
            global.addEventListener('online', () => this.reconnect());
            this.confirmDialog?.addEventListener('close', () => {
                const action = this.pendingConfirmAction;
                this.pendingConfirmAction = null;
                if (this.confirmDialog.returnValue === 'confirm' && action) {
                    this.executeInteraction(action);
                }
            });
        }

        open() {
            if (!this.panel.hidden) {
                return;
            }
            this.panel.hidden = false;
            this.launcher?.setAttribute('aria-expanded', 'true');
            this.activatePanel(true);
        }

        activatePanel(shouldFocus) {
            if (['launcher', 'panel'].includes(this.surface)) {
                this.previousFocus = global.document.activeElement;
                global.document.body.classList.add('veyra-chat-open');
                this.isolateModal(true);
                this.panel.addEventListener('keydown', this.boundTrap);
            }
            if (!this.historyLoaded) {
                this.loadHistory(true);
            }
            if (shouldFocus) {
                global.setTimeout(() => this.input.focus({ preventScroll: true }), 0);
            }
        }

        close() {
            if (!['launcher', 'panel'].includes(this.surface)) {
                return;
            }
            this.panel.hidden = true;
            this.launcher?.setAttribute('aria-expanded', 'false');
            global.document.body.classList.remove('veyra-chat-open');
            this.panel.removeEventListener('keydown', this.boundTrap);
            this.isolateModal(false);
            if (this.previousFocus && typeof this.previousFocus.focus === 'function') {
                this.previousFocus.focus();
            } else {
                this.launcher?.focus();
            }
        }

        isolateModal(active) {
            if (!active) {
                for (const record of this.inerted) {
                    record.node.inert = record.inert;
                    if (record.ariaHidden === null) {
                        record.node.removeAttribute('aria-hidden');
                    } else {
                        record.node.setAttribute('aria-hidden', record.ariaHidden);
                    }
                }
                this.inerted = [];
                return;
            }
            let branch = this.root;
            while (branch.parentElement && branch.parentElement !== global.document.body) {
                branch = branch.parentElement;
            }
            for (const node of Array.from(global.document.body.children)) {
                if (node === branch || ['SCRIPT', 'STYLE'].includes(node.tagName)) {
                    continue;
                }
                this.inerted.push({ node, inert: node.inert, ariaHidden: node.getAttribute('aria-hidden') });
                node.inert = true;
                node.setAttribute('aria-hidden', 'true');
            }
        }

        trapFocus(event) {
            // The confirmation is a native modal nested inside the chat panel.
            // Let the browser own its Escape key and focus cycle; applying the
            // outer panel trap here can hide the chat while leaving the confirm
            // dialog open or move focus behind the active modal.
            if (this.confirmDialog?.open) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = Array.from(this.panel.querySelectorAll(
                'button:not([disabled]):not([hidden]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
            )).filter((node) => !node.closest('[hidden]'));
            if (focusable.length === 0) {
                event.preventDefault();
                this.panel.focus();
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && global.document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && global.document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        async loadHistory(reset) {
            if (this.loadingHistory || this.sessionExpired || !global.navigator.onLine) {
                return false;
            }
            const acceptedPendingIds = reset ? Array.from(this.acceptedPendingNodes.keys()) : [];
            this.loadingHistory = true;
            this.setActivity(this.strings.processing || 'Loading conversation');
            try {
                const query = !reset && this.historyCursor ? { before: this.historyCursor } : {};
                if (this.conversationId) {
                    query.conversation_id = this.conversationId;
                }
                if (this.reconciliationRequired && isOpaqueId(this.reconciliationHandle)) {
                    query.reconciliation_handle = this.reconciliationHandle;
                }
                const envelope = await this.request('history', { method: 'GET', query });
                if (envelope.status !== 'succeeded') {
                    this.handleOperationFailure(envelope);
                    return false;
                }
                const value = operationValue(envelope);
                if (!isObject(value) || !Array.isArray(value.messages)) {
                    throw new RequestFailure('History contract mismatch.', 502, envelope, false);
                }
                let responseConversationId = this.conversationId;
                if (value.conversation_id !== null) {
                    responseConversationId = bindConversationId(this.conversationId, value.conversation_id);
                    if (responseConversationId === null || !messagesBelongToConversation(value.messages, responseConversationId)) {
                        throw new RequestFailure('History crossed the bound conversation.', 502, envelope, false);
                    }
                } else if (this.conversationId !== null || value.messages.length > 0) {
                    throw new RequestFailure('History omitted the bound conversation.', 502, envelope, false);
                }
                const messages = [];
                for (const message of value.messages) {
                    const validation = validateMessagePayload(message);
                    if (!validation.valid) {
                        throw new RequestFailure('A history message did not match the rendering contract.', 502, envelope, false);
                    }
                    messages.push(message);
                }
                this.conversationId = responseConversationId;
                if (reset) {
                    messages.sort((a, b) => Date.parse(a.created_at) - Date.parse(b.created_at));
                }
                for (const message of messages) {
                    this.acceptMessage(message, !reset);
                }
                this.historyCursor = typeof value.next_cursor === 'string' ? value.next_cursor : null;
                this.loadOlderButton.hidden = !this.historyCursor;
                this.historyLoaded = true;
                if (this.reconciliationRequired) {
                    if (value.reconciliation_complete === true) {
                        this.reconciliationRequired = false;
                        this.reconciliationHandle = null;
                        this.persistDraft();
                    } else {
                        this.connection.hidden = false;
                        this.connection.textContent = this.strings.delivery_uncertain || 'The previous outcome is still being reconciled. Sending remains disabled.';
                    }
                }
                this.renderQuickReplies(Array.isArray(value.quick_replies) ? value.quick_replies : []);
                this.rebuildDateSeparators();
                if (reset) {
                    this.scrollToLatest(false);
                }
                this.clearAcceptedPendingNodes(acceptedPendingIds);
                this.clearConnection();
                return true;
            } catch (error) {
                this.handleRequestFailure(error);
                return false;
            } finally {
                this.loadingHistory = false;
                this.setActivity('');
                if (this.postSendRefreshQueued) {
                    this.postSendRefreshQueued = false;
                    Promise.resolve().then(() => this.refreshHistoryAfterAcceptedSend());
                }
            }
        }

        acceptMessage(message, prepend) {
            const existing = this.messages.get(message.message_id);
            if (existing) {
                if (existing.rendering_version !== message.rendering_version || JSON.stringify(existing) !== JSON.stringify(message)) {
                    this.announceIntegrityPreserved();
                }
                this.updateCurrentState(existing.message_id, message.current_state || null);
                return;
            }
            if (!shouldReplaceHistorical(existing, message)) {
                return;
            }
            const immutableCopy = JSON.parse(JSON.stringify(message));
            this.messages.set(message.message_id, immutableCopy);
            const node = this.renderMessage(immutableCopy);
            this.messageNodes.set(message.message_id, node);
            if (prepend) {
                const firstMessage = this.timeline.querySelector('.veyra-message');
                this.timeline.insertBefore(node, firstMessage || this.empty);
            } else {
                this.timeline.appendChild(node);
            }
            this.empty.hidden = this.messages.size > 0;
        }

        renderMessage(message) {
            const item = createElement('li', 'veyra-message veyra-message--' + message.sender);
            item.dataset.messageId = message.message_id;
            item.dataset.renderingVersion = message.rendering_version;
            item.dataset.state = message.state;
            item.dataset.createdAt = message.created_at;
            item.id = this.root.id + '-message-' + message.message_id.replace(/[^A-Za-z0-9_-]/g, '-');
            item.dir = message.content.direction || 'auto';

            const meta = createElement('div', 'veyra-message__meta');
            const author = createElement('span', 'veyra-message__badge', this.senderLabel(message.sender));
            const time = createElement('time', null, this.formatTime(message.created_at));
            time.dateTime = message.created_at;
            meta.append(author, time);
            item.appendChild(meta);

            if (message.reply_quote) {
                item.appendChild(this.renderQuote(message.reply_quote));
            }

            if (message.content.text || message.content.modality_label) {
                const bubble = createElement('div', 'veyra-message__bubble');
                bubble.dir = message.content.direction || 'auto';
                bubble.textContent = message.content.text || message.content.modality_label;
                item.appendChild(bubble);
            }

            if (Array.isArray(message.product_references) && message.product_references.length > 0) {
                const references = createElement('div', 'veyra-message__references');
                for (const reference of message.product_references) {
                    const card = this.renderProductReference(reference, message.message_id);
                    if (card) {
                        references.appendChild(card);
                    }
                }
                item.appendChild(references);
            }

            if (Array.isArray(message.components) && message.components.length > 0) {
                const components = createElement('div', 'veyra-message__components');
                for (const component of message.components) {
                    const card = this.renderComponent(component, message.message_id);
                    if (card) {
                        components.appendChild(card);
                    }
                }
                item.appendChild(components);
            }

            const footer = createElement('div', 'veyra-message__actions');
            if (message.state === 'delivered') {
                const reply = createElement('button', 'veyra-message__reply', this.strings.reply || 'Reply');
                reply.type = 'button';
                reply.addEventListener('click', () => this.setReplyDraft({
                    messageId: message.message_id,
                    text: (message.content.text || message.content.modality_label || '').slice(0, 180)
                }));
                footer.appendChild(reply);
            }
            const status = createElement('span', 'veyra-message__status', this.messageStateLabel(message.state));
            footer.appendChild(status);
            item.appendChild(footer);
            return item;
        }

        renderQuote(quote) {
            const wrapper = createElement('div', 'veyra-message__quote');
            const sender = createElement('strong', null, this.senderLabel(quote.source_sender || 'system'));
            const excerpt = createElement('span', null);
            excerpt.dir = 'auto';
            excerpt.textContent = quote.source_available === false
                ? (this.strings.original_unavailable || 'Original message unavailable')
                : (quote.excerpt || quote.modality_label || '');
            wrapper.append(sender, excerpt);
            if (quote.source_available !== false && isOpaqueId(quote.source_message_id)) {
                const jump = createElement('button', null, this.strings.history || 'View source');
                jump.type = 'button';
                jump.addEventListener('click', () => this.jumpToMessage(quote.source_message_id));
                wrapper.appendChild(jump);
            }
            return wrapper;
        }

        renderProductReference(reference, sourceMessageId) {
            const authoritativeSourceMessageId = productReferenceSourceMessageId(reference);
            const binding = productReferenceBinding(reference);
            if (authoritativeSourceMessageId === null || binding === null) {
                return null;
            }
            const component = {
                component_id: reference.reference_id,
                type: 'product',
                snapshot: reference.snapshot,
                current_state: reference.current_state || null,
                actions: [{
                    action_id: 'ask:' + reference.reference_id,
                    label: this.strings.ask_about || 'Ask about this product',
                    kind: 'compose',
                    intent_text: '',
                    product_reference_binding: binding
                }]
            };
            return this.renderProductCard(component, sourceMessageId, true);
        }

        renderComponent(component, sourceMessageId) {
            if (!COMPONENT_TYPES.has(component.type)) {
                return null;
            }
            if (component.type === 'product') {
                return this.renderProductCard(component, sourceMessageId, false);
            }
            if (component.type === 'cart') {
                return this.renderCartCard(component, sourceMessageId);
            }
            if (component.type === 'checkout') {
                return this.renderCheckoutCard(component, sourceMessageId);
            }
            if (component.type === 'order') {
                return this.renderOrderCard(component, sourceMessageId);
            }
            if (component.type === 'crm_case') {
                return this.renderServiceCard(component, sourceMessageId, this.strings.crm_case || 'CRM case');
            }
            if (component.type === 'payment_review') {
                return this.renderServiceCard(component, sourceMessageId, this.strings.payment_review || 'Payment review');
            }
            return this.renderGenericCard(component, sourceMessageId);
        }

        cardShell(component, defaultTitle) {
            const snapshot = component.snapshot || {};
            const card = createElement('article', 'veyra-card veyra-card--' + component.type);
            card.dataset.componentId = component.component_id;
            const header = createElement('header', 'veyra-card__header');
            const headingWrap = createElement('div');
            const eyebrow = createElement('div', 'veyra-card__eyebrow', readableValue(snapshot.eyebrow) || defaultTitle);
            const title = createElement('h3', 'veyra-card__title', readableValue(snapshot.title || snapshot.name || snapshot.label) || defaultTitle);
            headingWrap.append(eyebrow, title);
            header.appendChild(headingWrap);
            const statusValue = readableValue(snapshot.status || component.current_state?.status);
            if (statusValue) {
                const status = createElement('span', 'veyra-card__status', statusValue);
                status.dataset.tone = this.statusTone(statusValue);
                header.appendChild(status);
            }
            card.appendChild(header);
            return card;
        }

        renderProductCard(component, sourceMessageId, isReference) {
            const snapshot = component.snapshot || {};
            const current = isObject(component.current_state) ? component.current_state : null;
            const card = this.cardShell(
                component,
                isReference ? (this.strings.product_reference || 'Product reference') : (this.strings.product || 'Product')
            );
            const layout = createElement('div', 'veyra-card__product-layout');
            const imageUrl = safeUrl(snapshot.image_url, global.location.origin);
            if (imageUrl) {
                const image = createElement('img', 'veyra-card__image');
                image.src = imageUrl;
                image.alt = typeof snapshot.image_alt === 'string' ? snapshot.image_alt : '';
                image.loading = 'lazy';
                layout.appendChild(image);
            } else {
                const placeholder = createElement('div', 'veyra-card__image');
                placeholder.setAttribute('aria-hidden', 'true');
                layout.appendChild(placeholder);
            }
            const details = createElement('ul', 'veyra-card__details');
            this.addFact(details, this.strings.variation || 'Variation', snapshot.variation);
            this.addFact(details, this.strings.quantity || 'Quantity', snapshot.quantity);
            this.addFact(details, this.strings.shown_price || 'Shown price', snapshot.price);
            this.addFact(details, this.strings.shown_stock || 'Shown stock', snapshot.stock);
            this.addFact(details, this.strings.why_fits || 'Why this fits', snapshot.why_fits);
            if (current) {
                this.addFact(details, this.strings.current || 'Current state', current.current_price || current.price);
                this.addFact(details, this.strings.current_stock || 'Current stock', current.stock);
                this.addFact(details, this.strings.purchasable || 'Purchasable', current.purchasable);
            } else if (snapshot.requires_current_state === true) {
                const unavailable = createElement('li', 'veyra-card__notice', this.strings.current_unavailable || 'Current state unavailable');
                details.appendChild(unavailable);
            }
            layout.appendChild(details);
            card.appendChild(layout);
            const stamp = createElement('div', 'veyra-card__stamp', this.strings.historical || 'Shown as it appeared then');
            card.appendChild(stamp);
            const actions = this.renderActions(component, sourceMessageId, current !== null || snapshot.requires_current_state !== true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderCartCard(component, sourceMessageId) {
            const snapshot = component.snapshot || {};
            const card = this.cardShell(component, this.strings.cart_result || 'Cart result');
            this.appendLineGroup(card, this.strings.changed || 'Changed', snapshot.changed_lines);
            this.appendLineGroup(card, this.strings.unchanged || 'Unchanged', snapshot.unchanged_lines);
            this.appendLineGroup(card, this.strings.could_not_change || 'Could not change', snapshot.failures, true);
            const totals = createElement('div', 'veyra-card__totals');
            this.addFact(totals, this.strings.discounts || 'Discounts', snapshot.discounts || snapshot.coupons, true);
            this.addFact(totals, this.strings.fees || 'Fees', snapshot.fees, true);
            this.addFact(totals, this.strings.tax || 'Tax', snapshot.tax, true);
            this.addFact(totals, this.strings.shipping || 'Shipping', snapshot.shipping_status || snapshot.shipping, true);
            this.addFact(totals, this.strings.current_total || 'Current total', snapshot.total, true, true);
            if (totals.childElementCount > 0) {
                card.appendChild(totals);
            }
            const actions = this.renderActions(component, sourceMessageId, true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderCheckoutCard(component, sourceMessageId) {
            const snapshot = component.snapshot || {};
            const card = this.cardShell(component, this.strings.checkout || 'Checkout');
            const facts = createElement('div', 'veyra-card__list');
            this.addFact(facts, this.strings.fulfillment || 'Fulfillment', snapshot.fulfillment, true);
            this.addFact(facts, this.strings.contact || 'Contact', snapshot.contact, true);
            this.addFact(facts, this.strings.shipping_method || 'Shipping method', snapshot.shipping_method, true);
            this.addFact(facts, this.strings.payment_method || 'Payment method', snapshot.payment_method, true);
            this.addFact(facts, this.strings.subtotal || 'Subtotal', snapshot.subtotal, true);
            this.addFact(facts, this.strings.discounts || 'Discounts', snapshot.discounts, true);
            this.addFact(facts, this.strings.fees || 'Fees', snapshot.fees, true);
            this.addFact(facts, this.strings.tax || 'Tax', snapshot.tax, true);
            this.addFact(facts, this.strings.shipping || 'Shipping', snapshot.shipping, true);
            this.addFact(facts, this.strings.final_total || 'Final total', snapshot.total, true, true);
            card.appendChild(facts);
            if (snapshot.stale === true || snapshot.recalculating === true || snapshot.complete !== true) {
                card.appendChild(createElement('div', 'veyra-card__notice', snapshot.stale_reason || this.strings.confirmation_unavailable || 'Confirmation is unavailable until every material value is fresh and complete.'));
            }
            const actions = this.renderActions(component, sourceMessageId, snapshot.stale !== true && snapshot.recalculating !== true && snapshot.complete === true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderOrderCard(component, sourceMessageId) {
            const snapshot = component.snapshot || {};
            const card = this.cardShell(component, this.strings.order || 'Order');
            const facts = createElement('div', 'veyra-card__list');
            this.addFact(facts, this.strings.order_number || 'Order number', snapshot.order_number, true);
            this.addFact(facts, this.strings.order_status || 'Order status', snapshot.order_status, true);
            this.addFact(facts, this.strings.payment_status || 'Payment status', snapshot.payment_status, true);
            this.addFact(facts, this.strings.fulfillment_status || 'Fulfillment status', snapshot.fulfillment_status, true);
            this.addFact(facts, this.strings.tracking_status || 'Tracking status', snapshot.tracking_status, true);
            this.addFact(facts, this.strings.current_total || 'Current total', snapshot.total, true, true);
            card.appendChild(facts);
            const actions = this.renderActions(component, sourceMessageId, true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderServiceCard(component, sourceMessageId, label) {
            const snapshot = component.snapshot || {};
            const card = this.cardShell(component, label);
            const facts = createElement('div', 'veyra-card__list');
            this.addFact(facts, label === (this.strings.crm_case || 'CRM case') ? (this.strings.case || 'Case') : (this.strings.review || 'Review'), snapshot.reference || snapshot.case_number || snapshot.review_number, true);
            this.addFact(facts, this.strings.submission_status || 'Submission status', snapshot.submission_status, true);
            this.addFact(facts, this.strings.decision_status || 'Decision status', snapshot.decision_status, true);
            this.addFact(facts, this.strings.execution_status || 'Execution status', snapshot.execution_status || snapshot.transition_status, true);
            this.addFact(facts, this.strings.current_service_status || 'Current service status', snapshot.current_status, true);
            this.addFact(facts, this.strings.order_status || 'Order status', snapshot.order_status, true);
            this.addFact(facts, this.strings.payment_status || 'Payment status', snapshot.payment_status, true);
            card.appendChild(facts);
            if (snapshot.decision_status && !snapshot.execution_status && !snapshot.transition_status) {
                card.appendChild(createElement('div', 'veyra-card__notice', this.strings.decision_execution_separate || 'A review decision is separate from any WooCommerce execution.'));
            }
            const actions = this.renderActions(component, sourceMessageId, true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderGenericCard(component, sourceMessageId) {
            const snapshot = component.snapshot || {};
            const card = this.cardShell(component, component.type.replace(/_/g, ' '));
            const facts = createElement('div', 'veyra-card__list');
            for (const [key, value] of Object.entries(snapshot)) {
                if (['title', 'label', 'name', 'status', 'eyebrow', 'actions'].includes(key) || isObject(value) || Array.isArray(value)) {
                    continue;
                }
                this.addFact(facts, key.replace(/_/g, ' '), value, true);
            }
            if (facts.childElementCount > 0) {
                card.appendChild(facts);
            }
            const actions = this.renderActions(component, sourceMessageId, true);
            if (actions.childElementCount > 0) {
                card.appendChild(actions);
            }
            return card;
        }

        renderActions(component, sourceMessageId, currentStateUsable) {
            const wrapper = createElement('div', 'veyra-card__actions');
            const actions = Array.isArray(component.actions) ? component.actions : [];
            for (const action of actions) {
                const validation = validateAction(action);
                if (!validation.valid) {
                    continue;
                }
                const button = createElement('button', 'veyra-card__action', action.label);
                button.type = 'button';
                button.dataset.actionId = action.action_id;
                if (action.secondary === true || action.kind === 'compose') {
                    button.classList.add('veyra-card__action--secondary');
                }
                if (action.disabled === true || !currentStateUsable || this.actionExpired(action)) {
                    button.disabled = true;
                }
                button.addEventListener('click', () => this.activateAction(action, sourceMessageId, component.component_id, button));
                wrapper.appendChild(button);
            }
            return wrapper;
        }

        activateAction(action, sourceMessageId, componentId, button) {
            if (action.kind === 'compose') {
                const binding = normalizeProductReferenceBinding(action.product_reference_binding);
                if (binding !== null) {
                    this.addProductReference({
                        binding,
                        label: action.product_label || action.label
                    });
                }
                if (action.intent_text) {
                    this.input.value = action.intent_text;
                }
                this.input.focus();
                this.persistDraft();
                this.updateComposerState();
                return;
            }
            if (action.kind === 'navigate') {
                const destination = safeUrl(action.url, global.location.origin);
                if (destination) {
                    global.location.assign(destination);
                }
                return;
            }
            const invocation = Object.assign({}, action, {
                source_message_id: sourceMessageId,
                component_id: componentId,
                button
            });
            if (invocation.requires_confirmation === true) {
                this.pendingConfirmAction = invocation;
                this.confirmSummary.textContent = invocation.confirmation_summary;
                this.showDialog(this.confirmDialog);
                return;
            }
            this.executeInteraction(invocation);
        }

        async executeInteraction(action) {
            if (this.inFlightActions.has(action.action_id) || !global.navigator.onLine) {
                return;
            }
            if (this.actionExpired(action)) {
                this.showError(this.strings.confirmation_expired || 'This confirmation expired. Refresh the conversation for a current summary.');
                this.loadHistory(true);
                return;
            }
            this.inFlightActions.add(action.action_id);
            if (action.button) {
                action.button.disabled = true;
            }
            this.setActivity(this.strings.processing || 'Processing');
            try {
                const command = {
                    schema_version: 'veyra.interaction_command.v1',
                    action_id: action.action_id,
                    source_message_id: action.source_message_id,
                    component_id: action.component_id,
                    confirmation_id: action.confirmation_id || null,
                    summary_message_id: action.summary_message_id || null,
                    state_hash: action.state_hash || null
                };
                const envelope = await this.request('interaction', {
                    method: 'POST',
                    body: command,
                    idempotencyKey: action.idempotency_key
                });
                if (envelope.status === 'succeeded' || envelope.status === 'partial') {
                    await this.loadHistory(true);
                    if (envelope.status === 'partial') {
                        this.handleOperationFailure(envelope);
                    }
                } else {
                    this.handleOperationFailure(envelope);
                }
            } catch (error) {
                this.handleRequestFailure(error);
            } finally {
                this.inFlightActions.delete(action.action_id);
                if (action.button) {
                    action.button.disabled = this.actionExpired(action);
                }
                this.setActivity('');
            }
        }

        addFact(parent, label, value, asDiv, strong) {
            const output = typeof value === 'boolean'
                ? (value ? (this.strings.yes || 'Yes') : (this.strings.no || 'No'))
                : readableValue(value);
            if (output === null) {
                return;
            }
            const row = createElement(asDiv ? 'div' : 'li', strong ? 'veyra-card__total veyra-card__total--strong' : 'veyra-card__fact');
            row.appendChild(createElement('span', null, label));
            const valueElement = createElement('span');
            addBidiText(valueElement, output, 'auto');
            row.appendChild(valueElement);
            parent.appendChild(row);
        }

        appendLineGroup(card, heading, lines, failure) {
            if (!Array.isArray(lines) || lines.length === 0) {
                return;
            }
            const group = createElement('section');
            group.appendChild(createElement('h4', 'veyra-card__eyebrow', heading));
            const list = createElement('ul', 'veyra-card__list');
            for (const line of lines) {
                const item = createElement('li', failure ? 'veyra-card__notice' : null);
                if (typeof line === 'string') {
                    item.textContent = line;
                } else if (isObject(line)) {
                    const name = line.name || line.label || line.product || 'Item';
                    const details = [line.variation, line.quantity, line.result || line.reason].filter(Boolean).join(' · ');
                    item.textContent = details ? name + ' — ' + details : name;
                }
                list.appendChild(item);
            }
            group.appendChild(list);
            card.appendChild(group);
        }

        updateCurrentState(messageId, currentState) {
            if (!currentState || !this.messageNodes.has(messageId)) {
                return;
            }
            const node = this.messageNodes.get(messageId);
            let region = node.querySelector('[data-current-state]');
            if (!region) {
                region = createElement('div', 'veyra-card__notice');
                region.dataset.currentState = 'true';
                node.appendChild(region);
            }
            region.textContent = (this.strings.current || 'Current state') + ': ' + (currentState.label || currentState.status || 'refreshed');
        }

        async submitDraft(extraBinding, existingCommand) {
            const text = existingCommand ? existingCommand.text : this.input.value.trim();
            if (!text || this.sessionExpired || !global.navigator.onLine) {
                if (!global.navigator.onLine) {
                    this.showOffline();
                }
                return;
            }
            if (existingCommand) {
                if (!isOpaqueId(existingCommand.conversation_id)
                    || existingCommand.conversation_id !== this.conversationId
                ) {
                    this.showError(this.strings.conversation_changed || 'This retry belongs to a different conversation and was not sent.');
                    return;
                }
            } else if (!this.conversationId && !(await this.createAndBindConversation(false))) {
                return;
            }
            let command;
            try {
                command = existingCommand || {
                    schema_version: 'veyra.customer_message_command.v1',
                    client_message_id: this.newOpaqueId('msg'),
                    conversation_id: this.conversationId,
                    text,
                    language: global.document.documentElement.lang || this.configuration.locale || 'en',
                    direction: 'auto',
                    reply_to_message_id: this.replyDraft ? this.replyDraft.messageId : null,
                    product_references: productReferenceBindings(this.productReferenceDrafts),
                    answer_binding: extraBinding || null
                };
            } catch (error) {
                this.handleRequestFailure(error);
                return;
            }
            this.pendingCommands.set(command.client_message_id, command);
            const pendingNode = this.renderPendingMessage(command);
            this.timeline.appendChild(pendingNode);
            this.empty.hidden = true;
            this.scrollToLatest(true);
            this.sendButton.disabled = true;
            this.setActivity(this.strings.sending || 'Sending message');

            try {
                const envelope = await this.request('send', {
                    method: 'POST',
                    body: command,
                    idempotencyKey: command.client_message_id
                });
                const policy = deriveRetryPolicy(envelope);
                if (envelope.status !== 'succeeded' && envelope.status !== 'partial') {
                    this.markPendingFailed(pendingNode, envelope, policy, command);
                    this.handleOperationFailure(envelope);
                    return;
                }
                const value = operationValue(envelope);
                if (!isObject(value) || bindConversationId(this.conversationId, value.conversation_id) === null) {
                    throw new RequestFailure('The response crossed the bound conversation.', 502, envelope, false);
                }
                const returned = extractReturnedMessages(value);
                if (!returned.valid || !messagesBelongToConversation(returned.messages, this.conversationId)) {
                    throw new RequestFailure('Accepted message response did not match the rendering contract.', 502, envelope, false);
                }
                for (const message of returned.messages) {
                    this.acceptMessage(message, false);
                }
                if (Array.isArray(value.quick_replies)) {
                    this.renderQuickReplies(value.quick_replies);
                }
                this.activeTurnId = isOpaqueId(value.turn_id) ? value.turn_id : null;
                this.stopButton.hidden = !this.activeTurnId || this.configuration.capabilities?.stop_response !== true;
                this.pendingCommands.delete(command.client_message_id);
                this.markPendingAccepted(pendingNode, command.client_message_id);
                this.clearAcceptedDraft();
                if (envelope.status === 'partial') {
                    this.reconciliationRequired = true;
                    this.reconciliationHandle = command.client_message_id;
                    this.persistDraft();
                    this.updateComposerState();
                }
                this.rebuildDateSeparators();
                this.scrollToLatest(true);
                await this.refreshHistoryAfterAcceptedSend();
            } catch (error) {
                const envelope = error instanceof RequestFailure ? error.envelope : null;
                const policy = error instanceof RequestFailure && error.uncertain
                    ? 'reconcile'
                    : (envelope ? deriveRetryPolicy(envelope) : 'reconcile');
                this.markPendingFailed(pendingNode, envelope, policy, command);
                this.handleRequestFailure(error);
            } finally {
                this.setActivity('');
                this.updateComposerState();
            }
        }

        renderPendingMessage(command) {
            const item = createElement('li', 'veyra-message veyra-message--shopper');
            item.dataset.pendingId = command.client_message_id;
            item.dataset.state = 'sending';
            const bubble = createElement('div', 'veyra-message__bubble', command.text);
            bubble.dir = 'auto';
            item.appendChild(bubble);
            item.appendChild(createElement('span', 'veyra-message__status', this.strings.sending || 'Sending'));
            return item;
        }

        markPendingAccepted(node, clientMessageId) {
            node.dataset.state = 'delivered';
            node.dataset.acceptedAwaitingHistory = 'true';
            const status = node.querySelector('.veyra-message__status');
            if (status) {
                status.textContent = this.strings.delivered_server || 'Delivered to server';
            }
            node.querySelectorAll('[data-safe-retry]').forEach((button) => button.remove());
            this.acceptedPendingNodes.set(clientMessageId, node);
        }

        clearAcceptedPendingNodes(clientMessageIds) {
            for (const clientMessageId of clientMessageIds) {
                const node = this.acceptedPendingNodes.get(clientMessageId);
                if (node) {
                    node.remove();
                }
                this.acceptedPendingNodes.delete(clientMessageId);
            }
        }

        async refreshHistoryAfterAcceptedSend() {
            if (this.loadingHistory) {
                this.postSendRefreshQueued = true;
                return false;
            }
            return this.loadHistory(true);
        }

        markPendingFailed(node, envelope, retryPolicy, command) {
            node.dataset.state = envelope?.status === 'uncertain' ? 'uncertain' : 'failed';
            const status = node.querySelector('.veyra-message__status');
            if (status) {
                status.textContent = envelope?.status === 'uncertain'
                    ? (this.strings.delivery_uncertain || 'Delivery uncertain')
                    : (this.strings.failed || 'The message was not accepted');
            }
            if (retryPolicy === 'manual_safe' && !node.querySelector('[data-safe-retry]')) {
                const retry = createElement('button', 'veyra-chat__secondary-button', this.strings.retry_safe || 'Retry safely');
                retry.type = 'button';
                retry.dataset.safeRetry = 'true';
                retry.addEventListener('click', () => {
                    retry.disabled = true;
                    node.remove();
                    this.submitDraft(null, command);
                }, { once: true });
                node.appendChild(retry);
            } else if (retryPolicy === 'reconcile') {
                this.reconciliationRequired = true;
                const value = envelope ? operationValue(envelope) : null;
                this.reconciliationHandle = isObject(value)
                    && value.conversation_id === command.conversation_id
                    && isOpaqueId(value.reconciliation_handle)
                    ? value.reconciliation_handle
                    : command.client_message_id;
                this.persistDraft();
                this.updateComposerState();
                const refresh = createElement('button', 'veyra-chat__secondary-button', this.strings.refresh || 'Refresh conversation');
                refresh.type = 'button';
                refresh.addEventListener('click', () => this.loadHistory(true));
                node.appendChild(refresh);
            }
        }

        renderQuickReplies(replies) {
            this.quickReplies.replaceChildren();
            if (this.configuration.capabilities?.quick_replies !== true || !Array.isArray(replies)) {
                this.quickReplies.hidden = true;
                return;
            }
            for (const reply of normalizeQuickReplies(replies)) {
                const button = createElement('button', 'veyra-chat__quick-reply', reply.label);
                button.type = 'button';
                button.addEventListener('click', () => {
                    this.input.value = reply.label;
                    this.submitDraft({
                        schema_version: 'veyra.answer_binding.v1',
                        choice_id: reply.choice_id,
                        pending_question_id: reply.pending_question_id
                    });
                });
                this.quickReplies.appendChild(button);
            }
            this.quickReplies.hidden = this.quickReplies.childElementCount === 0;
        }

        setReplyDraft(reply) {
            this.replyDraft = reply;
            this.draftQuote.hidden = !reply;
            if (reply) {
                this.draftQuoteText.textContent = reply.text || this.strings.quote_pending || 'Reply selected';
            } else {
                this.draftQuoteText.textContent = '';
            }
            this.updateDraftContext();
            this.persistDraft();
        }

        addProductReference(reference) {
            const binding = normalizeProductReferenceBinding(isObject(reference) ? reference.binding : null);
            if (binding === null || this.productReferenceDrafts.some((item) => item.binding.reference_id === binding.reference_id)) {
                return;
            }
            if (this.productReferenceDrafts.length >= MAX_PRODUCT_REFERENCES) {
                this.showError(this.strings.maximum_references || 'A message can reference at most three products.');
                return;
            }
            this.productReferenceDrafts.push({ binding, label: reference.label });
            this.renderDraftReferences();
            this.updateDraftContext();
            this.persistDraft();
        }

        renderDraftReferences() {
            this.draftReferences.replaceChildren();
            for (const reference of this.productReferenceDrafts) {
                const item = createElement('li', 'veyra-chat__draft-reference');
                item.appendChild(createElement('span', null, reference.label || this.strings.reference_pending || 'Product reference'));
                const remove = createElement('button', 'veyra-chat__icon-button', '×');
                remove.type = 'button';
                remove.setAttribute('aria-label', this.strings.remove_reference || 'Remove product reference');
                remove.addEventListener('click', () => {
                    this.productReferenceDrafts = this.productReferenceDrafts.filter(
                        (candidate) => candidate.binding.reference_id !== reference.binding.reference_id
                    );
                    this.renderDraftReferences();
                    this.updateDraftContext();
                    this.persistDraft();
                });
                item.appendChild(remove);
                this.draftReferences.appendChild(item);
            }
        }

        updateDraftContext() {
            this.draftContext.hidden = !this.replyDraft && this.productReferenceDrafts.length === 0;
        }

        async stopResponse() {
            if (!this.activeTurnId || this.stopButton.disabled) {
                return;
            }
            this.stopButton.disabled = true;
            try {
                const envelope = await this.request('cancel', {
                    method: 'POST',
                    body: { schema_version: 'veyra.cancel_turn_command.v1', turn_id: this.activeTurnId },
                    idempotencyKey: this.newOpaqueId('cancel')
                });
                if (envelope.status === 'succeeded') {
                    this.setActivity(this.strings.cancelled || 'Response stopped');
                    this.activeTurnId = null;
                    this.stopButton.hidden = true;
                } else {
                    this.handleOperationFailure(envelope);
                }
            } catch (error) {
                this.handleRequestFailure(error);
            } finally {
                this.stopButton.disabled = false;
            }
        }

        async startNewConversation() {
            if (this.configuration.capabilities?.new_conversation !== true) {
                return;
            }
            await this.createAndBindConversation(true);
        }

        async createAndBindConversation(resetSurface) {
            try {
                const envelope = await this.request('new_conversation', {
                    method: 'POST',
                    body: { schema_version: 'veyra.new_conversation_command.v1' },
                    idempotencyKey: this.newOpaqueId('conversation')
                });
                const value = operationValue(envelope);
                if (envelope.status !== 'succeeded' || !isObject(value) || !isOpaqueId(value.conversation_id)) {
                    this.handleOperationFailure(envelope);
                    return false;
                }
                const previousId = this.conversationId;
                if (resetSurface) {
                    this.removeStoredDraft(previousId);
                }
                this.conversationId = value.conversation_id;
                if (resetSurface) {
                    this.resetConversationSurface();
                    this.clearAcceptedDraft();
                } else {
                    this.persistDraft();
                    if (previousId !== this.conversationId) {
                        this.removeStoredDraft(previousId);
                    }
                }
                return true;
            } catch (error) {
                this.handleRequestFailure(error);
                return false;
            }
        }

        resetConversationSurface() {
            this.timeline.querySelectorAll('.veyra-message, .veyra-chat__separator').forEach((node) => node.remove());
            this.messages.clear();
            this.messageNodes.clear();
            this.pendingCommands.clear();
            this.acceptedPendingNodes.clear();
            this.historyCursor = null;
            this.historyLoaded = true;
            this.reconciliationRequired = false;
            this.reconciliationHandle = null;
            this.renderQuickReplies([]);
            this.empty.hidden = false;
        }

        async request(routeKey, options) {
            const route = this.configuration.routes?.[routeKey];
            const url = resolveApiUrl(this.configuration.rest_base, route, global.location.origin);
            if (!url) {
                throw new RequestFailure('The configured REST route is invalid.', 0, null, false);
            }
            if (isObject(options.query)) {
                for (const [key, value] of Object.entries(options.query)) {
                    if (value !== null && value !== undefined) {
                        url.searchParams.set(key, String(value));
                    }
                }
            }
            const controller = new AbortController();
            const timeout = global.setTimeout(() => controller.abort(), Number(this.configuration.request_timeout_ms || 90000));
            const headers = { 'Accept': 'application/json', 'X-Veyra-Correlation-ID': this.newOpaqueId('web') };
            Object.assign(headers, requestSecurityHeaders(this.configuration.nonce, global.document.cookie));
            if (options.idempotencyKey) {
                headers['Idempotency-Key'] = options.idempotencyKey;
            }
            if (options.body !== undefined) {
                headers['Content-Type'] = 'application/json';
            }
            let response;
            try {
                response = await global.fetch(url.href, {
                    method: options.method || 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    headers,
                    body: options.body === undefined ? undefined : JSON.stringify(options.body),
                    signal: controller.signal
                });
            } catch (error) {
                throw new RequestFailure(
                    error && error.name === 'AbortError' ? 'The request timed out.' : 'The request could not be completed.',
                    0,
                    null,
                    true
                );
            } finally {
                global.clearTimeout(timeout);
            }
            let envelope = null;
            try {
                envelope = await response.json();
            } catch (error) {
                throw new RequestFailure('The server response was not valid JSON.', response.status, null, response.status >= 500);
            }
            const validation = validateOperationEnvelope(envelope);
            if (!validation.valid) {
                throw new RequestFailure('The server response did not match the public contract.', response.status, null, response.status >= 500);
            }
            if (response.status === 401 || envelope.code === 'session_expired') {
                this.expireSession();
            }
            if (!response.ok && envelope.status === 'succeeded') {
                throw new RequestFailure('HTTP and operation states conflict.', response.status, envelope, true);
            }
            return envelope;
        }

        handleOperationFailure(envelope) {
            const value = operationValue(envelope);
            const message = isObject(value) && typeof value.message === 'string'
                ? value.message
                : (envelope.status === 'uncertain'
                    ? (this.strings.delivery_uncertain || 'The outcome is uncertain. Refresh before retrying.')
                    : (this.strings.failed || 'The request did not complete.'));
            this.showError(message + (envelope.correlation_id ? ' [' + envelope.correlation_id + ']' : ''));
        }

        handleRequestFailure(error) {
            if (error instanceof RequestFailure && error.envelope) {
                this.handleOperationFailure(error.envelope);
                return;
            }
            this.showError(error instanceof Error ? error.message : (this.strings.failed || 'The request did not complete.'));
        }

        expireSession() {
            this.sessionExpired = true;
            this.persistDraft();
            this.connection.hidden = false;
            this.connection.replaceChildren();
            this.connection.appendChild(global.document.createTextNode(this.strings.session_expired || 'Your session expired.'));
            const loginUrl = safeUrl(this.configuration.login_url, global.location.origin);
            if (loginUrl) {
                const link = createElement('a', null, ' Sign in');
                link.href = loginUrl;
                this.connection.appendChild(link);
            }
            this.updateComposerState();
        }

        showOffline() {
            this.persistDraft();
            this.connection.hidden = false;
            this.connection.textContent = this.strings.offline || 'You are offline. Nothing will be sent automatically.';
            this.updateComposerState();
        }

        async reconnect() {
            this.connection.hidden = false;
            this.connection.textContent = this.strings.reconnecting || 'Connection restored. Refreshing without resending anything.';
            this.updateComposerState();
            await this.loadHistory(true);
            this.updateComposerState();
        }

        clearConnection() {
            if (!this.sessionExpired && !this.reconciliationRequired && global.navigator.onLine) {
                this.connection.hidden = true;
                this.connection.textContent = '';
            }
        }

        restoreDraft() {
            const storage = this.draftStorage();
            if (!storage) {
                return;
            }
            try {
                const raw = storage.getItem(draftKey(this.configuration.actor_scope, this.conversationId));
                if (!raw) {
                    return;
                }
                const draft = JSON.parse(raw);
                if (!isObject(draft) || draft.schema_version !== 'veyra.draft.v1') {
                    return;
                }
                this.reconciliationRequired = draft.reconciliation_required === true;
                this.reconciliationHandle = isOpaqueId(draft.reconciliation_handle) ? draft.reconciliation_handle : null;
                this.input.value = typeof draft.text === 'string' ? draft.text.slice(0, this.input.maxLength) : '';
                if (isOpaqueId(draft.reply_to_message_id)) {
                    this.setReplyDraft({ messageId: draft.reply_to_message_id, text: this.strings.quote_pending || 'Reply selected' });
                }
                if (Array.isArray(draft.product_reference_bindings)) {
                    this.productReferenceDrafts = productReferenceBindings(draft.product_reference_bindings)
                        .map((binding) => ({ binding, label: this.strings.reference_pending || 'Product reference' }));
                    this.renderDraftReferences();
                    this.updateDraftContext();
                }
                this.autosizeComposer();
            } catch (error) {
                // A blocked or malformed draft store never blocks the chat.
            }
        }

        persistDraft() {
            const storage = this.draftStorage();
            if (!storage) {
                return;
            }
            const payload = {
                schema_version: 'veyra.draft.v1',
                text: this.input.value,
                reply_to_message_id: this.replyDraft ? this.replyDraft.messageId : null,
                product_reference_bindings: productReferenceBindings(this.productReferenceDrafts),
                reconciliation_required: this.reconciliationRequired,
                reconciliation_handle: this.reconciliationHandle,
                updated_at: new Date().toISOString()
            };
            try {
                storage.setItem(draftKey(this.configuration.actor_scope, this.conversationId), JSON.stringify(payload));
            } catch (error) {
                // Storage availability is optional; never lose the in-memory draft.
            }
        }

        clearAcceptedDraft() {
            const storage = this.draftStorage();
            if (storage) {
                try {
                    storage.removeItem(draftKey(this.configuration.actor_scope, this.conversationId));
                } catch (error) {
                    // No-op: the accepted server message is still authoritative.
                }
            }
            this.input.value = '';
            this.reconciliationRequired = false;
            this.reconciliationHandle = null;
            this.replyDraft = null;
            this.productReferenceDrafts = [];
            this.draftQuote.hidden = true;
            this.draftQuoteText.textContent = '';
            this.renderDraftReferences();
            this.updateDraftContext();
            this.autosizeComposer();
        }

        removeStoredDraft(conversationId) {
            const storage = this.draftStorage();
            if (!storage) {
                return;
            }
            try {
                storage.removeItem(draftKey(this.configuration.actor_scope, conversationId));
            } catch (error) {
                // Storage is optional and never controls server authority.
            }
        }

        draftStorage() {
            try {
                if (this.configuration.draft_storage === 'local') {
                    return global.localStorage;
                }
                if (this.configuration.draft_storage === 'session') {
                    return global.sessionStorage;
                }
            } catch (error) {
                return null;
            }
            return null;
        }

        rebuildDateSeparators() {
            this.timeline.querySelectorAll('.veyra-chat__separator').forEach((node) => node.remove());
            let previous = '';
            for (const node of Array.from(this.timeline.querySelectorAll('.veyra-message[data-created-at]'))) {
                const date = new Date(node.dataset.createdAt);
                const key = Number.isNaN(date.getTime()) ? '' : date.toISOString().slice(0, 10);
                if (key && key !== previous) {
                    const separator = createElement('li', 'veyra-chat__separator', this.formatDate(node.dataset.createdAt));
                    separator.setAttribute('role', 'separator');
                    this.timeline.insertBefore(separator, node);
                    previous = key;
                }
            }
        }

        jumpToMessage(messageId) {
            const node = this.messageNodes.get(messageId);
            if (!node) {
                this.showError(this.strings.original_unavailable || 'Original message unavailable');
                return;
            }
            node.scrollIntoView({ behavior: this.reducedMotion() ? 'auto' : 'smooth', block: 'center' });
            node.setAttribute('tabindex', '-1');
            node.focus({ preventScroll: true });
        }

        scrollToLatest(smooth) {
            if (!this.scroll) {
                return;
            }
            this.scroll.scrollTo({
                top: this.scroll.scrollHeight,
                behavior: smooth && !this.reducedMotion() ? 'smooth' : 'auto'
            });
            this.jumpButton.hidden = true;
        }

        updateJumpButton() {
            if (!this.scroll) {
                return;
            }
            const distance = this.scroll.scrollHeight - this.scroll.scrollTop - this.scroll.clientHeight;
            this.jumpButton.hidden = distance < 160;
        }

        autosizeComposer() {
            this.input.style.height = 'auto';
            this.input.style.height = Math.min(this.input.scrollHeight, 144) + 'px';
        }

        updateComposerState() {
            const unavailable = this.sessionExpired || this.reconciliationRequired || !global.navigator.onLine;
            this.input.disabled = unavailable;
            this.sendButton.disabled = unavailable || this.input.value.trim().length === 0;
        }

        announceIntegrityPreserved() {
            this.setActivity(this.strings.historical_preserved || 'Historical message content was preserved; refreshed state is shown separately.');
        }

        showError(message) {
            this.error.textContent = message || '';
        }

        setActivity(message) {
            this.activity.textContent = message || '';
        }

        showDialog(dialog) {
            if (!dialog) {
                return;
            }
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
                this.confirmSubmit?.focus();
            }
        }

        actionExpired(action) {
            return action.requires_confirmation === true && Date.parse(action.expires_at) <= Date.now();
        }

        formatTime(timestamp) {
            try {
                return new Intl.DateTimeFormat(this.configuration.locale || undefined, {
                    hour: 'numeric', minute: '2-digit', timeZone: this.configuration.display_timezone || undefined
                }).format(new Date(timestamp));
            } catch (error) {
                return timestamp;
            }
        }

        formatDate(timestamp) {
            try {
                return new Intl.DateTimeFormat(this.configuration.locale || undefined, {
                    dateStyle: 'medium', timeZone: this.configuration.display_timezone || undefined
                }).format(new Date(timestamp));
            } catch (error) {
                return timestamp;
            }
        }

        senderLabel(sender) {
            if (sender === 'ai') return (this.configuration.ai_name || 'Veyra') + ' · ' + (this.strings.ai_badge || 'AI');
            if (sender === 'human') return this.strings.human_badge || 'Store team';
            if (sender === 'system') return this.strings.system_badge || 'Store update';
            return this.strings.you || 'You';
        }

        messageStateLabel(state) {
            const labels = {
                sending: this.strings.sending || 'Sending',
                processing: this.strings.processing || 'Processing',
                delivered: this.strings.delivered_server || 'Delivered to server',
                failed: this.strings.failed || 'Failed',
                retrying: this.strings.retrying || 'Retrying',
                cancelled: this.strings.cancelled || 'Cancelled'
            };
            return labels[state] || state;
        }

        statusTone(value) {
            const normalized = String(value).toLowerCase();
            if (/(ready|complete|succeed|approved|paid|on)/.test(normalized)) return 'success';
            if (/(fail|error|reject|blocked|expired|cancel)/.test(normalized)) return 'danger';
            if (/(stale|pending|degrad|review|wait|uncertain)/.test(normalized)) return 'warning';
            return 'neutral';
        }

        reducedMotion() {
            return typeof global.matchMedia === 'function' && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
        }

        newOpaqueId(prefix) {
            if (global.crypto && typeof global.crypto.randomUUID === 'function') {
                return prefix + ':' + global.crypto.randomUUID();
            }
            const bytes = new Uint8Array(16);
            if (global.crypto && typeof global.crypto.getRandomValues === 'function') {
                global.crypto.getRandomValues(bytes);
                return prefix + ':' + Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
            }
            throw new RequestFailure(
                this.strings.secure_randomness_unavailable || 'Secure browser randomness is unavailable. Nothing was sent.',
                0,
                null,
                false
            );
        }
    }

    const contracts = Object.freeze({
        MAX_PRODUCT_REFERENCES,
        validateOperationEnvelope,
        validateMessagePayload,
        validateAction,
        isAbsoluteTimestamp,
        safeUrl,
        resolveApiUrl,
        deriveRetryPolicy,
        shouldReplaceHistorical,
        draftKey,
        bindConversationId,
        messagesBelongToConversation,
        readGuestCsrf,
        requestSecurityHeaders,
        extractReturnedMessages,
        validateProductReference,
        productReferenceSourceMessageId,
        normalizeQuickReplies,
        normalizeProductReferenceBinding,
        productReferenceBinding,
        productReferenceBindings
    });

    function mountRoots(documentRoot, configuration, createChat) {
        if (!documentRoot || typeof documentRoot.querySelectorAll !== 'function' ||
            !isObject(configuration) || configuration.enabled !== true) {
            return 0;
        }
        const factory = typeof createChat === 'function'
            ? createChat
            : (root, bootstrap) => new CustomerChat(root, bootstrap);
        let mounted = 0;
        documentRoot.querySelectorAll('[data-veyra-chat]').forEach((root) => {
            if (!root || !isObject(root.dataset) || root.dataset.veyraMounted === 'true') {
                return;
            }
            const chat = factory(root, configuration);
            if (!chat || typeof chat.boot !== 'function' || chat.boot() !== true) {
                return;
            }
            // Mark only a structurally complete, event-bound surface. An early
            // parser/DOM-insertion pass can then be retried safely.
            root.dataset.veyraMounted = 'true';
            mounted += 1;
        });
        return mounted;
    }

    global.VeyraChatContracts = contracts;
    if (typeof module !== 'undefined' && module.exports) {
        // Keep the injectable factory out of the browser global contract while
        // exposing the deterministic mounting boundary to the Node suite.
        module.exports = Object.freeze({ ...contracts, mountRoots });
    }

    function mount() {
        return mountRoots(global.document, global.VeyraChatBootstrap);
    }

    function installMountLifecycle() {
        if (!global.document) {
            return;
        }

        let observer = null;
        const finish = () => {
            mount();
            if (observer) {
                observer.disconnect();
                observer = null;
            }
        };

        // Cover footer/script strategy changes and parser-time surfaces without
        // leaving a page-lifetime observer behind. mount() is idempotent.
        if (global.document.readyState !== 'complete' &&
            global.document.documentElement && typeof global.MutationObserver === 'function') {
            observer = new global.MutationObserver(mount);
            observer.observe(global.document.documentElement, { childList: true, subtree: true });
        }

        mount();
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', mount, { once: true });
        }
        if (global.document.readyState !== 'complete' && typeof global.addEventListener === 'function') {
            global.addEventListener('load', finish, { once: true });
        } else {
            finish();
        }
    }

    installMountLifecycle();
})(typeof window !== 'undefined' ? window : globalThis);
