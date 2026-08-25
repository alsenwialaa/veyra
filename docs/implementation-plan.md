# Veyra greenfield implementation plan

## Scope and baseline

- Work mode: greenfield build.
- Canonical source: proposal v4.1, SHA-256 `44baae2afb053580028c2d8ae3372669c0a8d71d5a2c4990f899ef9d8b51b95b`.
- Release unit: Production Core; optional modules require separate certification.
- Current evidence baseline: candidate `0.1.7` declares database schema `1.6.0` and adds durable bounded Conversation Focus unresolved references; lifecycle transaction/failure containment; exact actor-wide cart/checkout serialization and uncertain-result handling; stricter privacy/media access; provider readiness/projection hardening; closed catalog/knowledge/recommendation shapes; authoritative quantity checks; and safer CRM/order/payment-review/idempotency transitions. It retains the schema 1.5 metadata-only Context Bundle manifests, pre-attestation prohibited-data redaction, strict current Gemini `steps` parsing, exact product-reference rebinding, and logged-in Veyra/WordPress/Woo customer/session checks. Frozen-tree verification passes all 27 PHP runner groups, 244 named domain scenarios plus four auxiliary suites, 262/262 source symbols, 9/9 Node contracts, and the checked-in Draft 2020-12 schema/registry runner. These remain local deterministic and static checks. Composer/PHPUnit, PHPStan, Plugin Check, browser/axe, and live WordPress/WooCommerce/MySQL/MariaDB/Gemini results are not recorded as passed here.
- Current product verdict: **NOT READY**.
- Explicit exclusions from the current candidate: merchant-policy invention, production compatibility claims, route certification without evaluation/privacy approval, and optional-module certification. Incomplete sensitive workflows remain unexposed rather than represented as working.

## Invariants for every slice

Each slice must map proposal sections, anchors, DoD items, feature keys, logical tools, actors, capabilities, authoritative sources, state transitions, invalidation, confirmation, idempotency, locking, retry/reconciliation, privacy, historical rendering, localization/accessibility, tests, and release evidence before exposure.

A slice is complete only when the path works:

`customer input -> resolved actor -> typed interpretation -> authorized plan -> current authoritative read -> exact validated scope -> confirmation when required -> idempotent execution -> authoritative observation -> verified customer result -> persistence/audit -> tests/evidence`

UI, prompt, schema, route, class, fake, or test stub presence alone is not completion.

## Dependency-ordered delivery program

| Stage | Deliverable | Mandatory gate before advancing |
|---|---|---|
| 0 | Definition package: architecture, data, state schemas, capabilities, features, tools, threats, privacy, compatibility, tests, lifecycle, runbooks, traceability | Open decisions explicit; no critical rule lives only in a prompt; schemas/authority/testability reviewable |
| 1 | Bootstrap/lifecycle: dependency-safe plugin boot, namespace/autoload, bounded install, versioned migration runner, health/diagnostics, deactivation/uninstall, CI | Activation makes no remote call, does no heavy work, and does not break storefront when dependencies are unavailable |
| 2 | Identity/policy: actor resolver, secure guest session, all 28 capabilities, ownership resolvers, feature state, audit, rate/correlation | Negative actor/capability/ownership/feature tests pass at every boundary |
| 3 | Persistence/files/queues: indexed repositories, migration checkpoints, protected storage, retention/export/erasure, queue adapter | Actor-scoped queries, idempotent/resumable migrations, protected-file denial, queue dedupe/dead-letter/recovery proven |
| 4 | Continuity: conversation/messages, Focus, Pending Questions, Journey State, Context Graph/Bundle, Conversation Memory, summaries, historical renderer base | Short-reply binding is deterministic after AI proposal; paused journeys isolated; no cross-customer context; no raw-transcript-only continuity |
| 5 | Provider/contracts/orchestration: Gemini adapter, manifest, readiness/privacy gate, interpretation/plan/response schemas, 155-tool registry, bounded loop, evidence/verification | Provider Unconfigured blocks shopper AI; runtime/admin use the same adapter; readiness is context-free and separately schematized; every shopper request and finalized provider body is closed and attested; malformed output cannot execute; no hidden semantic fallback |
| 6 | Knowledge/culture/location/time | Published/effective sources only; injection isolated; GPS explicit; time IANA/authoritative; unavailable evidence yields truthful block/fallback |
| 7 | Catalog/requirements/recommendation/exact resolution | No first-result selection; product/variation/unit/pack/quantity exact; hard constraints deterministic; cards only for presented products |
| 8 | Cart | Authoritative cart parity, exact line resolution, atomic or explicit partial compound semantics, idempotency, one final recalculation, truthful result |
| 9 | Persistent chat checkout | Fulfillment-first, contact/pickup review, Woo shipping/tax/payment/totals, natural-order input, interruption/resume, exact final confirmation and gateway reconciliation |
| 10 | Orders | Owned exact order, status separation, Customer Action Matrix parity, locked/recalculated amendment, financial route, CRM fallback |
| 11 | CRM/handoff | Draft/submission/decision/execution/resolution separated; equivalent case reuse; internal notes never exposed; authorship explicit |
| 12 | Offline payment review | Protected exact-order evidence; AI extraction advisory; reviewer decision and Woo transition separated; truthful transition failure |
| 13 | Multimodal input | Text parity; field uncertainty; media never authorizes; protected storage and correction proven |
| 14 | Messaging/history UI | Mobile/keyboard/safe-area/RTL; quotes/references context-only; retry/offline never replays side effects; shared immutable renderer |
| 15 | Five merchant products and publication | Capability separation, validated drafts, simulation, publish/schedule/rollback, effective-state cross-surface enforcement, monitor read-only |
| 16 | Optional modules, one at a time | Each stays Off/absent until its independent `MODULE READY` evidence exists |
| 17 | Hardening/release | All quality gates Passed, 35 anchors accepted, 64 DoD accepted, default Gemini route independently passes, operations/rollback exercised |

## Initial complete vertical slices

Implementation should proceed with dependency-preserving slices, not broad disconnected scaffolding:

1. **Lifecycle and diagnostic slice** — clean install, missing Woo, migration checkpoint, activation/no-network assertion, safe notice, deactivation, uninstall preservation.
2. **Actor/feature/audit denial slice** — guest/customer/staff/admin request, exact capability and ownership enforcement, Off/Blocked exposure removal, safe audit.
3. **Conversation-focus slice** — question creates Pending Question; fake provider proposes short binding; server validates schema/target/version; exact state update or minimal clarification; history and audit.
4. **Provider-readiness slice** — save credential reference, explicit bounded test through production adapter path, publish or remain Blocked; never reveal secret.
5. **Catalog-to-cart slice** — need interpretation, bounded candidates, hard filters, exact variation/quantity, authorized cart write, authoritative recalculation, verified result.
6. **Checkout-to-order slice** — durable journey from fulfillment classification through fresh final confirmation and exactly-once Woo order/gateway outcome.
7. **Owned-order-to-CRM slice** — current action matrix, direct denial, prefilled confirmed case, later current status, no false order-change claim.
8. **Offline-evidence slice** — protected upload, proposed OCR, exact summary/confirmation, idempotent review, staff decision, separately confirmed transition.

## Current dependency-ordered repair sequence

1. **Context Bundle structural/transmission slice — implemented locally, not accepted.** Preserve runtime ContextBundle/ProviderRequest attestations, exact phase/final-body gates, persisted modalities, blog-distinct site scope, readiness gates, non-default-route denial, five false certification flags, mutation accounting, and fail-closed route state.
2. **Durable manifest and complete source accounting — implemented locally in 0.1.7, not accepted.** Preserve the metadata-only, identity-level included/excluded ledger, actor-scoped read-back hash verification, and privacy/retention/re-key/uninstall wiring. Preserve the schema 1.6 bounded unresolved-reference round trip. Resolve `DEC-023`, source-deletion propagation, access/volume, live migration, backup/restore, and rollback before certification.
3. **Prohibited-data redaction — implemented locally in 0.1.7, not accepted.** Evaluate categories and false-positive/negative behavior, obtain privacy/legal approval, and run independent/live tests before changing the route flag.
4. **Provider-safe result projections — strengthened fail-closed implementation in 0.1.7, not accepted.** Preserve recursively closed catalog/knowledge/recommendation profiles and validated-only continuation construction; finish every provider-visible result profile and certify omission/redaction behavior against the exact route.
5. **Snapshot and identity coherence.** Exact product/variation reference and logged-in Woo actor/session checks are implemented locally. Define one versioned transactional snapshot, refresh authoritative state after Pending Question/tool mutation, rebind response claims/components, and complete the guest-to-Woo-session lifecycle.
6. **Contract execution path.** Preserve the checked-in Draft 2020-12 compiler/reference-resolution runner and the runtime tests mapped to `x-invariants`; execute Composer/PHPUnit, PHPStan, Plugin Check, coverage, integration, browser, and accessibility jobs in attributable CI.
7. **Catalog/product grounding — next capability blocker.** Implement a closed authoritative `catalog.get_product` read, exact selected-reference binding, an allowlisted server component projection, and deterministic claim/evidence compatibility. Preserve WooCommerce authority and keep every product claim blocked until live compatibility evidence exists.
8. **Requirement semantic promotion — next capability blocker.** Publish a complete typed proposal, run bounded semantic verification, execute through a server-only validated/idempotent/audited service, and replan/rebind the same turn after a successful change.
9. **Continuity, commerce, and live certification.** Validate memory/summaries and drift behavior; finish confirmation/idempotency/reconciliation-backed cart, checkout, order, CRM, and payment-review workflows; then run the live WordPress/WooCommerce/MySQL/HPOS/Action Scheduler/provider matrix.

The first item provides bounded local evidence only: **0/35 anchors and 0/64 DoD items remain accepted**, and the whole-product verdict remains **NOT READY**.

## Test evidence by layer

- Static: PHP syntax, WPCS, static types, Composer/package validation, JS types/lint, dependency/secret/forbidden-pattern/package scans.
- Unit: value objects, authority/precedence, feature state, invalidation, state machines, state hashes, canonical idempotency, retry safety.
- Schema/contract: all JSON/provider/tool/rendering/REST/job versions, hostile/oversized/unknown fields.
- Repository/migration: clean install, every supported prior version, repeat/partial/resume/concurrency, indexes/query budgets, retention/export/erasure/uninstall.
- WordPress/Woo integration: permissions/nonces, products/variations, sessions, cart/totals, shipping/tax, checkout/gateways, CRUD orders/refunds, My Account actions, HPOS, Store API, Blocks/classic, Action Scheduler.
- Deterministic orchestration: fake/recorded providers, tool denials, malformed responses, bounded repairs, no success before result.
- REST/security: IDOR, capability bypass, CSRF, mass assignment, injection, replay/race, protected files, callbacks, logs/secrets.
- Browser/E2E: complete customer/admin flows, mobile, reconnection, history, failure/degraded paths.
- Accessibility/localization: WCAG 2.2 AA critical flows, keyboard, screen reader, zoom, reduced motion, Arabic/English/RTL/mixed direction.
- Performance/reliability: approved reference environment, p50/p95/p99, context/tool budgets, queue/dead-letter, cache freshness, failure recovery.
- AI evaluation: versioned scenario packs, repeated samples, default route independent results, qualified language/cultural review.

## Rollout and rollback requirements

- Every migration is idempotent, bounded, checkpointed, resumable, and post-checked.
- Configuration and provider routes use draft/validate/simulate/publish/immutable version/rollback.
- New capabilities are exposed behind effective-state gates only after dependencies and evidence pass.
- Rollout starts with internal simulation and non-production stores, then a small monitored cohort, then gradual expansion.
- Any customer-isolation, unauthorized write, fabricated success, duplicate side effect, confirmation bypass, destructive migration, critical accessibility failure, or provider privacy breach triggers immediate stop/rollback and reconciliation.
- Code rollback never blindly rolls schema backward. Use compatibility windows, feature blocking, restore only from verified backup when necessary, and reconcile external side effects.

## Current completion rule

The candidate provides real implementation evidence for parts of Stages 1–15, including a runtime-attested transient Context Bundle, durable metadata-only source accounting, bounded durable focus references, sealed provider requests, an exact final-body gate, and mutation-aware failure persistence, but none of those stages is accepted complete. The tool registry remains one `tested`, seven `implemented_not_tested`, and 147 `contracted_not_implemented`; no commerce tool is certified or formally accepted. The route remains Unconfigured with all five independent Context/privacy/result-projection/Woo/snapshot certifications false, Context Bundle policy/operations remain unresolved, and the live integration/evaluation matrix has not produced accepted evidence. Stage 17 remains closed; **0/35 anchors and 0/64 DoD items are accepted**, and the release verdict is **NOT READY**.
