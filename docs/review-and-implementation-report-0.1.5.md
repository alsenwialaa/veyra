# Veyra 0.1.5 trace, review, and implementation report

## Executive decision

- Whole-release verdict: **NOT READY**
- Engineering candidate: `0.1.5`
- Database schema: `1.4.0` (unchanged)
- Context Bundle wire schema: `1.1.0`
- Canonical proposal: v4.1
- Evidence update: 2026-08-25
- Formal acceptance: **0/35 canonical anchors accepted; 0/64 Definition of Done items accepted**
- Frozen-tree PHP regression: 22/22 runners, 186 named scenarios plus 2 whole-runner contracts, 0 failures
- Hardened provider-boundary runner: 11/11 scenarios passed, including a real readiness-service-to-Gemini-adapter fixture
- Node contracts: 7/7 passed
- Static auxiliary evidence: 313/313 PHP files parsed, 248/248 source symbols loaded, and repository verifier passed
- Heuristic audit: 0 critical, 14 high, 30 medium; all high signals manually dispositioned as scanner leads, not independent security acceptance
- Archive inventories and artifact hashes: emitted in the separate `Veyra-AI-Commerce-Agent-0.1.5-SHA256SUMS.txt` deliverable after a double-build equality check

This hardening pass traced the current customer-turn path through persisted input, Context Bundle assembly, all provider-independent request phases, the finalized Gemini body, readiness/release policy, Pending Question consumption, WooCommerce context, schema boot gates, and failure persistence.

The candidate now has two runtime attestation boundaries, exact closed shopper phase envelopes, canonical equality for the finalized provider-specific request body, freshness/capability-aware readiness gating, persisted modality binding, Pending Question mutation accounting, multisite-distinct site scope, exact logged-customer Woo cart-snapshot binding, and exact database-schema equality before boot or transmission.

Those changes materially strengthen fail-closed behavior; they do not certify production transmission. The checked-in route remains Unconfigured and uncertified, and five independent route certification flags remain false. Durable Context Bundle manifest persistence, prohibited/sensitive-data classification and redaction, provider-safe ToolResult projection, coherent transactional snapshots and post-mutation freshness, full excluded-source identity accounting, exact product identity for multi-product references, complete guest-to-Woo-session binding, JSON Schema conformance execution, and all live platform/provider matrices remain release blockers.

## Canonical traceability disposition

All anchor and Definition of Done rows remain `Not assessed`. Evidence links and notes have been expanded for the hardened source, but no row has the complete implementation, live verification, documentation, owner, and named acceptance package required for promotion.

| Inventory | Candidate `0.1.5` state |
|---|---:|
| Canonical anchors | 35 total; 0 accepted |
| Definition of Done items | 64 total; 0 accepted |
| Logical-tool catalog | 155/155 design rows present |
| `tested` logical tools | 1 |
| `implemented_not_tested` logical tools | 7 |
| `contracted_not_implemented` logical tools | 147 |
| Formally accepted logical tools | 0 |
| Certified optional modules | 0 |

This hardening does not promote a logical tool. `context.get_runtime_clock` remains the sole catalog row marked `tested`; the two requirement and five recommendation rows remain governance-blocked `implemented_not_tested` entries. No commerce tool is certified.

## Hardened Context Bundle issuance

### Exact persisted input and modality binding

`CommerceAgent` persists the customer turn before assembly, including its safe render metadata: authorized reply snapshot, authorized product-reference snapshots, attachment identifiers, whether location was supplied, and the normalized quick-reply hint. `ContextBundleAssembler` then reloads the exact actor-owned stored message and reconstructs its modality inputs from that persisted record. The transient caller array cannot replace the stored reply, product-reference source, attachment count, location-presence flag, or quick-reply hint.

The stored text must exactly match the current turn and cannot be empty. Explicit reply text is reloaded again through actor-owned visible history. Historical product bodies are omitted; only validated source-message identifiers are retained. Attachments and location are counted as unsupported/omitted rather than transmitted. This closes transient/persisted modality drift, but it does not identify one exact product when a referenced historical message contains multiple products.

### Actor, site, focus, journey, and source alignment

The provider actor identifier is a per-bundle pseudonym. The site pseudonym is derived from the current WordPress blog identity, so two blog IDs do not share a provider site scope. This is collision hardening, not a multisite support claim: network-wide activation remains blocked and multisite certification remains deferred.

The contract requires one exact active journey consistent with Conversation Focus, rejects an inconsistent active-journey graph, binds every modality to the exact selected input, and reconciles all included source identities with selected focus, journey, recent-message, requirement, runtime, and authoritative-state data. Each included source retains version, freshness, classification, validated actor scope, authority, purpose, section, and selection reason.

The 13-section selection manifest reconciles available, included, and excluded counts. It still records only aggregate counts and reasons for excluded items; it does not preserve every excluded source identity. That missing identity-level exclusion ledger is an explicit blocker for complete historical reconstruction and erasure propagation.

### Runtime-only bundle attestation

`ContextBundle` has a private constructor and is issued with an HMAC from `ContextBundleAttestor`. The signature covers the canonical provider projection plus the raw server actor tuple. The provider gate accepts only a bundle verifiable by the exact attestor instance shared with the actor-owned assembler. A structurally valid bundle issued with a different runtime attestor is not provider authorization.

The bundle remains immutable and canonical-round-tripped. Its non-secret SHA-256 hash binds the exact provider projection across the decision, response, and semantic-verification phases; its HMAC attestation proves issuance by this runtime boundary. Neither attestation is persisted in the message correlation record.

## Hardened provider request and body boundary

### Complete provider-independent request attestation

`ProviderRequestAttestor` seals each provider request with a per-runtime HMAC over route, system instruction, complete input, tool declarations, response schema, timeout, metadata, traffic class, purpose, phase, and Context Bundle hash. The transmission gate rejects missing or invalid request attestations and does not accept continuation/function-result state in these transmitting paths.

Shopper phases are closed and exact:

- decision requires `agent_decision_v1`, the exact decision schema, timeout, instruction, authorized-tools array, and server call limits;
- response requires `agent_response_v1`, the exact response schema/instruction, validated decision, binding outcome, step outcomes, and typed tool results; and
- semantic verification requires `semantic_response_verification_v1`, its dedicated schema and exact candidate/result/context keys.

Each phase has one text input, no provider-native tools, exact metadata keys, and one embedded Context Bundle canonically identical to the attested bundle. Extra fields, wrong contracts, schema drift, wrong timeouts, changed limits, changed metadata, or a second embedded bundle fail closed.

### Exact finalized Gemini body

After the adapter maps the provider-independent request into Gemini form, `ProviderTransmissionGate::outboundDecision()` reruns the full request gate and independently constructs the only allowed final body. The body must be canonically identical in model, system instruction, input history, storage setting, response format/schema, and mapped tools, and must stay within the route request-byte limit. An extra provider-specific field or any unbound customer data is rejected.

Credential access occurs only after both the provider-independent request and finalized Gemini body pass. This closes the earlier gap where adapter mapping could theoretically add content after the last policy decision. It is local structural evidence, not proof of the live Gemini API surface, behavior, privacy settings, or model quality.

## Readiness, release, schema, and route state

Readiness traffic has a dedicated phase, purpose, closed response schema, exact timeout, exact nonce input, and one side-effect-free `diagnostics.probe` tool. It cannot carry a Context Bundle or shopper payload. The real readiness-service-to-Gemini-adapter path now validates both the closed structured output and exactly one native probe call; missing, malformed, or extra native calls fail. The explicit readiness service records credential reachability, structured-output, function-calling, and text capability results, but always leaves `release_certified=false`; a successful capability probe cannot certify the route.

This candidate publishes only `default_text_tool_orchestration`. The transmission gate rejects every other route ID, including a manifest-shaped fallback, until that route has its own exact architecture, state store, request/body contract, privacy controls, evaluation, and certification boundary. An alternate route cannot inherit the default route's evidence.

The release gate additionally requires:

- a fresh `checked_at` value within the route maximum age and not materially in the future;
- exact route ID and manifest version;
- all declared required capabilities, including text;
- independently certified readiness state and route publication;
- explicit shopper-transmission approval, published privacy policy, and passed evaluation; and
- all five independent route certifications.

The checked-in runtime route deliberately has these five flags set to `false`:

- `context_manifest_persistence_certified`
- `prohibited_data_filter_certified`
- `provider_result_projection_certified`
- `woocommerce_actor_binding_certified`
- `context_snapshot_consistency_certified`

It also remains `Unconfigured`, with shopper transmission, privacy publication, evaluation, and release certification false. Therefore the normal candidate cannot transmit shopper context.

`Plugin`, `RuntimeModule`, `SecurityLifecycleModule`, and the provider transmission gate now require the stored database schema version to equal `VEYRA_SCHEMA_VERSION` exactly. An older schema may enter bounded migration recovery; an unknown newer schema does not boot older runtime code and cannot authorize shopper transmission.

## WooCommerce actor boundary and snapshot limits

`WooAuthoritativeContextProvider` exposes a cart only when all of the following agree: Veyra actor type is `customer`, the server-resolved WordPress user ID is present, `get_current_user_id()` equals it, WooCommerce cart/customer objects exist, and `WC()->customer->get_id()` equals the same user ID. Guest, support, reviewer, mismatched account, or unavailable Woo state returns an unavailable authority snapshot.

This prevents a browser-global Woo cart from being attributed to an unrelated Veyra actor. It intentionally leaves guest cart context unavailable until a persisted, rotated, expiry-aware, account-link-aware guest-to-Woo-session binding exists and is live-tested.

The cart snapshot is read before decision and is reused inside the immutable bundle. Pending Question consumption and later tool mutations may change state after assembly. Typed mutation results are supplied to response and verification, but the bundle is not rebuilt from one coherent transactional snapshot and no general post-mutation authority refresh proves that every current claim observes the new state. `context_snapshot_consistency_certified=false` correctly keeps this route blocked.

## Pending Question mutation accounting

Successful Pending Question consumption now creates an authoritative typed `ToolResult` for `conversation.consume_pending_question`, including the question, binding, customer-message, validated value, and changed resource. That result participates in both tool-result evidence and mutation-result classification.

If a later provider phase fails, the completed consumption is no longer reported as a zero-mutation blocked turn. The persisted failure records a partial outcome and safe mutation result so the shopper is warned that state changed and should refresh before retrying. This improves failure truthfulness; it does not complete the full transactional/post-mutation freshness design.

## Safe persistence boundary

Successful and post-assembly failure messages persist only the correlation reference: bundle ID/schema/version/hash, conversation and turn, route and manifest version, transmission decision, whole-bundle counts, and lifetime. They do not store the provider projection, runtime attestations, source manifest, or selection manifest.

Database schema remains `1.4.0`. A durable metadata-only `context_bundle_manifests` table remains unimplemented pending retention, legal hold, access, export/erasure, source-deletion propagation, indexing/volume, migration, backup/restore, and rollback decisions. Consequently operators cannot reconstruct the complete historical inclusion/exclusion decision from message correlation alone.

## Focused deterministic evidence status

The hardened runners include scenarios for runtime attestor forgery, request-envelope opening, finalized-body injection, phase/digest reuse through the real `CommerceAgent`, post-consumption failure accounting, readiness freshness/capability/schema denial, readiness isolation, persisted modality binding, multisite-distinct scope, focus/journey parity, source/modality/timestamp drift, deterministic reduction, mandatory overflow, and correlation-only persistence.

The frozen-tree local regression passes 22/22 standalone PHP runners: 186 named scenarios plus the two whole-runner rendering-contract and requirement-state-repository contracts, with 0 failures. The provider-boundary subset passes 11/11, including the real readiness-service-to-Gemini-adapter fixture, alternate-route denial, and extra-native-call denial. Node contracts pass 7/7; 313/313 PHP files parse, 248/248 source symbols load, and the repository verifier passes. The heuristic audit reported 0 critical, 14 high, and 30 medium signals; its high signals have manual dispositions in `docs/audit-dispositions.md`. Official Composer/PHPUnit dependencies remain unavailable, so 21 PHPUnit classes were not run. These local results do not replace live WordPress/WooCommerce/provider integration, an independent security review, or named acceptance.

## Remaining release-blocking findings

### HIGH-01 — durable Context Bundle manifest lifecycle is absent

Only correlation metadata persists. A durable metadata-only manifest, its access controls, retention/legal-hold rules, export/erasure propagation, volume/index budgets, migration, backup/restore, rollback, and operations view remain unresolved.

### HIGH-02 — prohibited and sensitive content is not classified/redacted

The assembler uses allowlisted structural projections and omits unsupported blobs, but free text and authorized quotes/messages do not pass a certified prohibited/sensitive-data classifier and redactor. Structural minimization is not content inspection. The route flag remains false.

### HIGH-03 — provider ToolResult projection is not certified

Response and semantic-verification phases can carry typed Tool Results. Typed and schema-valid does not mean safe for an external provider: a dedicated allowlisted projection must remove provider-unnecessary or sensitive fields before sealing the request. `provider_result_projection_certified=false` blocks shopper transmission until every transmitting result type has that proof.

### HIGH-04 — snapshot consistency and post-mutation freshness are incomplete

Context sources are not captured in one coherent transactional snapshot, and the immutable pre-decision bundle may be stale after Pending Question consumption or commerce mutation. A typed tool result improves evidence but does not replace authoritative post-mutation refresh and claim/component rebinding.

### HIGH-05 — excluded-source identity accounting is incomplete

Selection counts and reasons reconcile, but identities/versions/classifications for every excluded item are not retained. This blocks complete historical diagnostics, erasure propagation, and durable manifest acceptance.

### HIGH-06 — product and guest session bindings remain incomplete

A product-reference modality identifies a source message, not one exact product/variation within a multi-product message. Guest Woo carts remain unavailable because no complete persisted guest-to-Woo-session binding exists.

### HIGH-07 — checked-in JSON Schema has no conformance runner

The dedicated PHP contract enforces the runtime projection and the JSON Schema documents the intended wire shape, but no standards-compliant Draft 2020-12 runner resolves the cross-file references and proves every runtime fixture against the checked-in schema in CI.

### HIGH-08 — grounding and semantic promotion remain incomplete

No authoritative catalog/product tool is certified for deterministic product prose/components, and no production service safely promotes typed requirement proposals with semantic evidence, audit, replay identity, conflict handling, and same-turn replan.

### HIGH-09 — live and formal acceptance are absent

No accepted live WordPress, WooCommerce, MySQL/MariaDB, HPOS, Store API, Blocks/classic, Action Scheduler, gateway, shipping/tax/fee, theme/extension, Gemini, browser/device, accessibility/RTL, security/privacy, load/concurrency, backup/restore, rollout, rollback, upgrade, or uninstall matrix exists. PHPUnit is unavailable. No anchor, DoD item, logical tool, provider route, or optional module has named acceptance.

## Required next sequence

1. Implement durable metadata-only Context Bundle manifests and full excluded-source identity accounting after lifecycle decisions are approved.
2. Add a prohibited/sensitive-data classifier/redactor before the external transmission boundary.
3. Define and enforce a provider-safe allowlisted projection for every typed Tool Result transmitted in response or semantic-verification phases.
4. Define coherent snapshot/version semantics, refresh authority after mutation, and rebind every current response claim/component.
5. Add exact product/variation identity to product references and a complete guest-to-Woo-session binding lifecycle.
6. Add a standards-compliant JSON Schema conformance runner and restore the PHPUnit/dependency test path.
7. Complete deterministic product grounding and trusted requirement semantic promotion.
8. Run every live platform, provider, security, privacy, accessibility/localization, performance, migration/recovery, rollout, and rollback matrix.
9. Freeze artifacts, rerun all deterministic verification, publish reproducible hashes, and obtain named acceptance for all anchors and DoD rows.

## Final release decision

Candidate `0.1.5` now has materially stronger issuance, request, adapter-body, readiness, schema, mutation-accounting, multisite-scope, and Woo actor boundaries. It is not a complete or production-certified commerce agent.

**Final verdict: NOT READY. Do not deploy this candidate to production.**
