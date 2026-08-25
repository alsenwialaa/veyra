# Feature, dependency, and effective-state registry

The machine-readable registry is `../config/contracts/feature-registry.json`. It contains all 20 Production Core keys and all 17 optional-module keys. Contract and runtime-state schemas are `feature-contract.schema.json` and `effective-feature-state.schema.json`.

## Product-completeness rule

Production Core capability implementation is mandatory even when a merchant may configure its active use Off. Optional means release-separable, not partial: an optional module remains absent from active customer UI, model tools, routes, jobs, webhooks, setup, and marketing until independently certified.

The four foundational keys—`ai_semantic_orchestration`, `ai_context_graph`, `ai_conversation_focus`, and `ai_conversation_memory`—cannot be merchant-disabled while Veyra is represented as an intelligent agent. They remain configured On but effectively Blocked because the available bounded implementation and deterministic evidence are not sufficient for certification or provider publication. Merchant-configurable core features start fail-closed Off until their publication policy is implemented. Optional modules start Off and `not_certified`.

## State calculation

1. If the merchant configured Off, effective state is Off.
2. If configured On but certification, actor/capability, policy, consent, dependency, market, adapter, provider, schema, migration, queue, storage, or fresh health gates fail, effective state is Blocked with stable reason/remediation.
3. Degraded is allowed only for a named, documented, tested fallback that preserves backend authority and the route's required quality floor. It never means a regex, keyword, canned-answer, fixed-question, or form-wizard substitute presented as AI.
4. On requires configured On and every required gate to pass.

The evaluator emits an immutable publication version and an exposure-manifest hash. A stale health result cannot support current On state.

## Cross-surface enforcement

Each feature contract must enumerate customer/admin components, REST routes, application services, model tools, jobs, webhooks, CLI commands, analytics events, and historical reads. Effective state is checked at every boundary, not only in UI.

When Off or Blocked:

- active controls, prompts, cards, proactive behavior, and model-visible tools are removed;
- new writes and feature recomputations fail without side effects;
- pending confirmations expire;
- jobs/webhooks are cancelled, superseded, or fail closed;
- authorized historical records may remain readable only when the contract permits;
- native WooCommerce behavior outside Veyra remains unchanged;
- only an authorized validated publication can enable the feature.

## Current registry evidence

The registry remains a design and release-control artifact. Route/job/UI arrays are intentionally empty until exact adapters exist. The versioned Context Bundle and send-time provider gate supply bounded evidence for the foundational path, but no entry is certified and no effective On claim is made. Tool associations are traceability mappings only; actual model exposure must additionally pass the universal tool contract and provider route/schema quality gates.
