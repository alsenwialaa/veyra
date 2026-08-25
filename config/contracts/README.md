# Contract registry

All contracts in this directory are versioned definition artifacts for proposal v4.1. They are not runtime or release evidence.

## Integrity and registries

- `proposal-manifest.json` — canonical source checksum/counts.
- `capabilities.json` — all 28 canonical WordPress capabilities; no default role grants are assumed.
- `feature-registry.json` — all 20 Production Core and 17 optional feature keys with initial fail-closed state/dependencies.
- `logical-tool-catalog.json` — all 155 logical tools; one is `tested`, seven requirement/recommendation entries are `implemented_not_tested` and remain governance-blocked, and 147 are `contracted_not_implemented`.
- `provider-route-manifest.yaml` — Gemini default family; exact release-selected model ID null; readiness Unconfigured; `store_requests=false`; shopper transmission, privacy publication, evaluation, release certification, and the five Context/privacy/result-projection/Woo/snapshot certifications are false.

## Contract schemas

- `feature-contract.schema.json` and `effective-feature-state.schema.json`.
- `universal-tool-contract.schema.json`.
- `scenario-pack.schema.json`.
- `schemas/common.schema.json`.
- `schemas/ai-interpretation.schema.json`, `ai-plan.schema.json`, `ai-response.schema.json`, and the legacy runtime `provider-turn.schema.json`.
- `schemas/agent-decision.schema.json` and `agent-response.schema.json` — strict runtime phase envelopes.
- `schemas/tool-result.schema.json` — versioned logical Tool Result envelope enforced after every handler.
- `schemas/evidence-ledger.schema.json` and `verification-result.schema.json`.
- `schemas/operation-result.schema.json`, `confirmation.schema.json`, and `idempotency-record.schema.json`.
- `schemas/context-graph.schema.json`.
- `schemas/conversation-focus.schema.json`.
- `schemas/pending-question.schema.json`.
- `schemas/journey-state.schema.json`.
- `schemas/context-bundle.schema.json` — closed provider wire schema `1.1.0`: exact persisted-turn and persisted-modality binding; pseudonymous actor plus WordPress-blog site scope; allowlisted selected data; versioned source/selection/privacy manifests; deterministic reduction; whole-bundle byte/item accounting; route policy and expiry. The full provider projection and runtime attestations remain transient. Candidate `0.1.7` separately persists a metadata-only actor-scoped source/selection manifest and carries the bounded, durably stored Conversation Focus unresolved-reference set.
- `schemas/conversation-memory.schema.json`.
- `schemas/requirement-state.schema.json` — the actor-owned, version/hash-bound complete requirement history introduced by schema 1.4.
- `schemas/validated-summary.schema.json`.
- `schemas/durable-preference.schema.json`.

## Contract discipline

Unknown versions, missing/extra fields, invalid enums/types, oversized payloads, unscoped resource IDs, stale versions, and unsupported provider/tool contracts must fail safely. A schema-valid model proposal is still untrusted: actor, feature, capability, ownership, current-state, confirmation, idempotency, privacy, and authority gates run server-side.

`ContextBundleContract` remains the runtime enforcement boundary for `context-bundle.schema.json`. The checked-in Ajv Draft 2020-12 runner resolves the registered cross-file references and compiles all 30 JSON definition files; `x-invariants` are counted and still require the named deterministic runtime tests because JSON Schema treats extension keywords as annotations. Assembly binds the bundle to the exact actor-owned persisted customer turn and modalities. `ContextBundleAttestor` seals the canonical projection plus raw server actor tuple; `ProviderRequestAttestor` separately seals every complete provider-independent request. Decision, response, semantic-verification, and readiness each have one closed phase envelope. Readiness must produce its closed structured output and exactly one native probe call through the real service-to-adapter path, and a failed provider result cannot report credentials or structured output as available. The provider gate accepts only the default route, rechecks current route/schema/release/freshness/capabilities, requires one identical Context Bundle, and reconstructs the exact allowed finalized Gemini body before credential access or network activity. Alternate/fallback routes cannot inherit the default route's certification. Passing these local contracts does not certify a provider route or commerce workflow.

Typed Tool Results remain internal authority/evidence contracts. Candidate `0.1.7` projects them through exact-version, recursively closed provider profiles before response or semantic-verification calls. Missing, open, dynamic-map, or unregistered result schemas fail closed. The catalog, knowledge, and recommendation handler surfaces now use closed list-shaped projections rather than dynamic maps, and the dormant provider continuation constructor accepts only a validated provider-safe projection. Coverage and independent acceptance remain incomplete, so `provider_result_projection_certified=false` remains required.

Database schema is `1.6.0`, and runtime composition/transmission requires exact stored-schema equality. Schema `1.5.0` introduced `context_bundle_manifests`; schema `1.6.0` adds `conversation_focus.unresolved_references_json` with an idempotent postcondition-checked migration. Focus persistence now validates actor ownership, row versions, identifier width, uniqueness, and bounded JSON before returning a projection. Manifest save still succeeds only after actor-scoped read-back hash verification. Guest re-key, privacy export/erasure, retention, legal hold, uninstall, and migration paths are wired, but the default Context Bundle retention period remains open under `DEC-023`, source-deletion propagation and live `dbDelta`/MySQL behavior are unaccepted, and rollback/volume/operations evidence is missing. Runtime HMAC attestations and the full provider projection are not persisted.

`context.get_runtime_clock` is the only catalog-tested tool. The requirement tools exercise exact closed inputs/outputs, while the five recommendation operations exercise closed inputs and closed success/stale data contracts. Live WooCommerce/policy-race, response-grounding, compatibility, and acceptance evidence remain absent. Catalog governance therefore blocks all seven `implemented_not_tested` entries; the remaining 147 schema URNs are stable placeholders and are likewise ineligible for runtime exposure.
