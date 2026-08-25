# Veyra 0.1.3 trace, review, and implementation report

## Executive decision

- Whole-release verdict: **NOT READY**
- Candidate: `0.1.3`
- Database schema: `1.3.0`
- Evidence date: 2026-08-24
- Canonical proposal: v4.1, SHA-256 `44baae2afb053580028c2d8ae3372669c0a8d71d5a2c4990f899ef9d8b51b95b`
- Supplied `0.1.2` source ZIP SHA-256: `d198e07f320dbebf8faab3a0eea5e1ef4d5cdbe3b5dbb6a06628950e42a8fc21`
- Source identity: uploaded ZIP; no Git commit or attributable upstream CI build was supplied

This pass traced the supplied implementation against all 35 canonical anchors and all 64 Definition of Done items, reviewed the AI/context, commerce, and platform/security paths, and implemented the next connected safety slice. The live shopper path now uses separate strict decision and response contracts, server-authorized ordered plan execution, and atomic one-time Pending Question consumption. These changes close the two named `0.1.2` orchestration defects; they do not make the whole commerce product complete.

The release gate remains closed. Production governance now admits only one individually tested dependency-light read, `context.get_runtime_clock`; no commerce tool is certified, major commerce and continuity workflows remain incomplete, provider activation/certification is unavailable, and every mandatory live/formal acceptance gate remains unexecuted.

## Implemented in 0.1.3

### Strict AI phase separation and server plan authority

`CommerceAgent` no longer calls the legacy combined `agent_turn_v1` contract. The live path is now:

1. assemble the bounded actor-owned context;
2. request and independently validate `agent_decision_v1`;
3. validate any AI-proposed short-reply binding;
4. execute only the authorized tool subset of the ordered plan;
5. request and independently validate `agent_response_v1` from the typed execution results;
6. run deterministic claim/component verification;
7. run the independent semantic verification contract; and
8. persist the customer-visible result and validated state proposal.

`DecisionPlanExecutor` rechecks exact tool name/version/classification, dependencies, control boundaries, confirmation class, server/tool budgets, mutation permission, idempotency identity, and typed execution outcomes. Provider-native tool calls are not accepted in the decision or response phase. The provider receives the governed tool catalog as quoted planning data; the registry remains the execution authority.

### AI-proposed Pending Question binding with atomic consumption

The quick-reply object supplied by the browser is now a bounded, exact-shape, explicitly untrusted hint. It cannot become a validated binding by itself. The strict interpretation contract must propose the semantic value and exact Pending Question/resource target.

The backend validates the proposal against:

- the current actor-owned foreground focus;
- the exact active Pending Question and focus/question versions;
- the expected answer type and allowed choice set;
- the exact focused resource IDs;
- current runtime/cart/commerce dependency versions;
- expiry/invalidation state; and
- confirmation sensitivity.

Schema `1.3.0` adds a durable binding ID, source customer-message ID, and validated binding record to `veyra_pending_questions`. `consumePendingQuestion()` locks the exact focus and question, performs compare-and-set checks, writes the answer record, marks the question answered, advances and clears the focus, and commits once. A replay sees the advanced focus and cannot authorize another write. Replacing a focus now invalidates its old active question in the same transaction.

Confirmation-sensitive short replies remain fail-closed in this slice because they require independent lookup and validation of the complete server confirmation record. A generic affirmative is not promoted by this new path.

### Upgrade and request-boundary hardening

- Plugin version is `0.1.3`; database schema is `1.3.0`.
- Existing installations run the bounded migration path before runtime composition; runtime/security modules require the declared schema version and do not query newer columns on an incomplete upgrade.
- The REST and domain request boundaries require an exact three-field quick-reply hint with bounded opaque identifiers.
- Privacy export exposes binding/source identifiers but does not export internal raw orchestration data.

### First individually tested logical tool

`context.get_runtime_clock` is the only catalog row promoted to `tested`. It has an exact empty input, a closed successful-result schema, a concrete handler, guest/customer and `ai_time_awareness` gates, a strict planning profile, current-time/freshness evidence, and malformed-output fail-closed coverage. `ToolDefinition` can now carry a per-tool output schema, `ToolResultValidator` enforces it for data-bearing successful/partial/stale results, and catalog governance refuses any `tested` or `accepted` definition without a closed output schema.

This is intentionally not bulk certification. The remaining 154 rows stay `contracted_not_implemented`, and the shopper path remains blocked while the provider/AI route release gate is unconfigured.

### Serialized configuration publication and safer administration

Configuration revision append now takes a bounded, per-product MySQL named lock before starting its transaction, then locks draft/published heads in a fixed order and reconciles the exact inserted public ID. This serializes even concurrent first revisions where no row exists yet; a stale expected head rolls back without inserting.

Publish guards the exact draft and published heads together. Publish and rollback write the immutable revision and internal WordPress option on the same database transaction, verify the option row directly rather than trusting object-cache state, commit once, and invalidate option caches only after commit. The action fails closed unless both the revision and `wp_options` tables report InnoDB. These controls have deterministic fake-database coverage; live MySQL/MariaDB concurrency, object-cache, connection-loss, and recovery evidence is still mandatory.

Commerce drafts now require a complete registered-feature map, and a newly created draft snapshots every effective registered setting. This prevents a partial payload from silently replacing unrelated feature state. Agent product state separately exposes `manage_models`, the Commerce Control screen restores staged feature selections after reload, and dynamic feature controls have accessible names. Localization and live browser/assistive-technology acceptance remain open.

### Fail-closed platform and customer-surface hardening

When `ai_semantic_orchestration` is not effectively available, the runtime now registers neither the public chat REST controller nor the customer launcher. Published AI name, disclosure, formality, response length, and fallback language are bounded and mapped through server-owned policy strings; unknown configuration cannot become prompt text. Logged-in browser drafts are namespaced by the WordPress user ID.

Customer and admin mutation identifiers now require Web Crypto and fail closed when it is unavailable. Protected storage refuses a path beneath the web-server document root, logical safe-area spacing covers RTL layouts, and network-wide multisite activation is explicitly rejected before installation writes. These controls have deterministic fixtures only; deployed web-server, persistent-cache, browser/device, and live multisite behavior remain unverified.

## Remaining release-blocking findings

### HIGH-01 — no commerce tool is certified

Only `context.get_runtime_clock` is `tested`. `UniversalToolGovernance` exposes it solely for guest/customer contexts while `ai_time_awareness` is available. The real plugin composition still advertises and executes no certified catalog, recommendation, cart, checkout, order, CRM, payment-review, knowledge, requirements, or memory tools.

The other 154 statuses were intentionally preserved. Physical handlers and local fake tests are not sufficient certification evidence. Each exact tool needs strict input and output contracts, actor/capability/ownership/feature/dependency checks, confirmation/idempotency/recovery where applicable, live integration tests, and named acceptance before its catalog row can change.

### HIGH-02 — per-tool output typing is complete for only one logical tool

`ToolDefinition` and `ToolResultValidator` now support closed per-tool output schemas, and catalog governance requires one for any `tested` or `accepted` row. Only `context.get_runtime_clock` supplies and exercises that schema. Malformed nested business data can still pass the generic boundary for the 154 uncertified tools in test compositions where governance is disabled.

Required closure: versioned, closed per-tool output schemas with status/result-code parity and catalog/runtime validation before any result is authoritative.

### HIGH-03 — deterministic response grounding can be bypassed by omitted claims

`ResponseVerifier` requires a claim ledger after successful tools, but a no-tool response can contain material prose with an empty claim list and pass the deterministic check. The semantic verifier is supplemental model judgment and cannot be the only critical truth boundary.

Required closure: every material response segment/component must be bound to server-generated claim/evidence identifiers or composed from typed authoritative fields; unsupported no-tool commerce claims and provider self-approval must fail deterministically.

### HIGH-04 — mandatory continuity beyond focus remains disconnected

Conversation Memory model proposals remain deliberately blocked; summary and Journey State have read paths but no complete typed write/CAS/supersession lifecycle. Unresolved references are not fully rehydrated. The current Context Bundle is byte-bounded but not a canonical per-item source/selection manifest, and oversized non-message sections fail instead of being independently selected and bounded.

Required closure: actor-scoped versioned repositories/services for requirements, corrections, refusals, memory, summaries, open loops, journey pause/resume, source erasure propagation, and bounded source manifests.

### HIGH-05 — requirements and recommendations are not bound to server-owned complete state

Requirement mutation is server-only and has no completed AI semantic-promotion caller. Recommendation input still accepts a requirement array rather than loading an exact actor-owned version. A model can omit or alter a hard budget/exclusion in non-production test compositions; compatibility and branch-dependent resolution also remain incomplete.

Required closure: source-bound requirements persistence plus expected-version recommendation execution against the server-owned complete set.

### HIGH-06 — core commerce workflows terminate before required execution

- Ordinary cart writes lack one shared Woo-session authority lock/reload across concurrent conversations.
- Checkout stops at preview; order placement, confirmation issuance, gateway handoff, callback/return recovery, and authoritative retry verification are unpublished.
- Customer Action Matrix reads exist, but confirmed cancel/change/reorder executors are absent.
- CRM and offline-payment review stop at draft; confirmed submission, staff decision, separate commerce execution, resolution, and later status are absent.

These paths fail closed and do not fabricate success, but the promised customer journeys cannot complete.

### HIGH-07 — shopper provider release cannot be certified through the current product path

The route remains `Unconfigured` with shopper transmission, privacy publication, evaluation, and release certification false. This is correct. There is no complete versioned acceptance workflow capable of publishing `release_certified=true` after exact capability, privacy, quality, freshness, and owner checks.

### HIGH-08 — live matrices and formal acceptance are absent

No live WordPress/WooCommerce/MySQL/MariaDB, HPOS, Store API, Blocks/classic, gateway, shipping/tax/fee, theme/extension, Gemini, browser/device/assistive-technology, Arabic/RTL, security, load/concurrency, backup/restore, rollout, or rollback matrix was available. All 35 anchor rows and all 64 DoD rows therefore remain formally `Not assessed`; no row was promoted to accepted.

## Other confirmed gaps

- Admin bootstrap advertises schedule/import operations without matching registered routes.
- The admin UI still contains untranslated strings; the newly named dynamic controls have not received live assistive-technology or Arabic/RTL acceptance.
- Partial uninstall is not resumable across a large dataset.
- Catalog filtering has no continuation cursor beyond its bounded first window.
- Saved Woo customer contact/address is not hydrated into a new checkout session.
- Cart-change checkout invalidation is described in the tool result but not transactionally persisted.
- The checkout record lacks several required journey/resume/confirmation/gateway/uncertain-result fields.
- Shopper REST currently does not expose the complete multimodal/location path promised by `AgentTurnInput`.

## Traceability disposition

`VYR-AI-002`, `VYR-AI-003`, `VYR-CTX-001`, and `VYR-CON-003` now have materially stronger implementation and deterministic test evidence. They remain formally `Not assessed` because each anchor also requires broader tool-output, recovery, scenario, live integration, documentation, and named acceptance evidence. No partial implementation is represented as canonical completion.

| Inventory | Candidate `0.1.3` state |
|---|---:|
| Canonical logical-tool rows | 155/155 present |
| Physical canonical handler definitions | 93/155 |
| Catalog rows marked tested/accepted | 1/155 (`context.get_runtime_clock`) |
| Capabilities represented | 28/28 |
| Production Core features represented/certified | 20/20 represented; 0/20 certified |
| Optional modules represented/certified | 17/17 represented; 0/17 certified |
| Formal anchors accepted | 0/35 |
| Formal DoD items accepted | 0/64 |

## Verification record

| Check | Observed result | Evidence boundary |
|---|---:|---|
| PHP 8.2 repository parse | 292/292 passed | Syntax only |
| PHP 8.2 source-symbol load | 236/236 passed | Autoloadability only |
| Standalone PHP contracts/scenarios | 121 passed, 0 failed | Domain/contract fakes, not live Woo |
| AI/context governance scenarios | 16/16 passed | Includes the one tested clock tool and malformed-output denial |
| Prompt-policy scenarios | 5/5 passed | Published-policy compilation fixtures, not live provider behavior |
| Strict orchestration scenarios | 8/8 passed | Tool-plan authorization and control boundaries |
| Pending Question consumption scenarios | 7/7 passed | Transaction/CAS fixture, not live MySQL |
| Configuration revision safety scenarios | 11/11 passed | Named-lock/transaction/option fixtures, not live MySQL or object cache |
| Migration scenarios | 9/9 passed | Migration fixtures, not live `dbDelta`/MySQL |
| Product-configuration scenarios | 5/5 passed | Closed-schema/complete-feature-map fixtures |
| Node UI/accessibility/security contracts | 7/7 passed | Static contracts, not browser E2E |
| `scripts/verify_release.py` | passed | Static inventory/version/REST/localization checks |
| Veyra heuristic audit | 0 critical, 9 high, 21 medium | Heuristic signals manually dispositioned |
| Official Composer/PHPUnit/dependency audit | not run | Composer/development dependencies unavailable |
| Live runtime/provider/browser matrices | not run | Release-blocking |

The 121 PHP results comprise foundation 32, catalog 3, cart 1, checkout 6, orders 4, payment/media 7, CRM 4, provider 2, AI/context 16, prompt policy 5, strict orchestration 8, Pending Question consumption 7, configuration revision safety 11, migration 9, product configuration 5, and rendering 1.

## Required next sequence

1. Extend strict per-tool output schemas beyond the certified runtime clock and deterministically complete response-to-evidence grounding.
2. Complete server-owned requirements/memory/summary/journey persistence and canonical Context Bundle selection manifests.
3. Build and certify one exact useful read-to-write commerce vertical slice without bulk-promoting the tool catalog.
4. Complete checkout/order/gateway, CRM, payment-review, operations, privacy, and lifecycle workflows.
5. Run the full live compatibility, provider, accessibility/localization, security, performance, recovery, rollout, and rollback matrices.
6. Attach attributable evidence and named acceptance to every mandatory traceability row.

## Final release decision

Candidate `0.1.3` closes the strict-phase and replay-safe Pending Question defects identified in `0.1.2`. It is a safer and more coherent engineering candidate, not a completed production agent.

**Final verdict: NOT READY. Do not deploy this candidate to production.**
