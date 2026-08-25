# Veyra engineering candidate and assurance package

Status: **implemented engineering candidate — NOT READY for production release**.

This package combines the canonical Veyra v4.1 design baseline with an installable modular-monolith plugin, runtime contracts, migrations, user interfaces, deterministic tests, and release evidence. Substantial Production Core paths are implemented and bounded quality/platform/browser CI passes, but incomplete core tool coverage and unassessed broad compatibility, privacy, accessibility, security, provider-evaluation, operational, and acceptance gates prevent a production claim.

## Canonical authority

- Proposal: `Veyra_AI_Commerce_Agent_for_WooCommerce_Production_Proposal_v4.1_Final(6).md`
- Version/date: 4.1 / 2026-08-24
- SHA-256: `44baae2afb053580028c2d8ae3372669c0a8d71d5a2c4990f899ef9d8b51b95b`
- Integrity record: `canonical-source.md` and `../config/contracts/proposal-manifest.json`

## Design documents

- `architecture.md` — modular-monolith boundaries, authority model, domain map, and end-to-end execution path.
- `implementation-plan.md` — dependency-ordered greenfield program and vertical-slice completion rules.
- `open-decisions.md` — unresolved product, merchant, legal, provider, support, and operational decisions.
- `actor-capability-action-matrix.md` — actor, ownership, capability, and action separation.
- `data-schema-lifecycle.md` — proposed records, indexes, migrations, rollback, deactivation, and uninstall.
- `feature-registry.md` — configured/effective state and cross-surface enforcement.
- `threat-model.md` — trust boundaries, abuse cases, controls, and required verification.
- `confirmation-idempotency-recovery.md` — confirmation, idempotency, locks, callbacks, and uncertain outcomes.
- `privacy-retention-files.md` — data inventory, external transmission, retention, rights, and protected files.
- `test-strategy.md` — deterministic, integration, E2E, security, compatibility, accessibility, and AI evaluation strategy.
- `compatibility-matrix.csv` — proposed minima and release matrix; every row remains `Not assessed`, and the bounded three-cell smoke does not promote support.
- `runbooks/` — incident, rollout, and rollback procedures.
- `traceability/` — all 35 anchors and all 64 Definition of Done items, with no unsupported completion claims.
- `release-evidence.md` — current evidence verdict: **NOT READY**.
- `review-and-implementation-report-0.1.7.md` — current candidate subsystem/feature trace, schema 1.6 focus continuity, lifecycle and commerce-write repairs, privacy/media/provider hardening, deterministic results, and exact release blockers.
- `review-and-implementation-report-0.1.6.md` — prior durable metadata-only Context Bundle candidate retained as a baseline.
- `review-and-implementation-report-0.1.5.md` — prior transient Context Bundle candidate retained as a baseline.
- `review-and-implementation-report-0.1.4.md` — prior actor-owned requirement-state/recommendation-binding candidate retained as a baseline.
- `review-and-implementation-report-0.1.3.md` — prior strict-orchestration/Pending Question candidate retained as a baseline.
- `review-and-implementation-report-0.1.2.md` — prior candidate trace and repair evidence retained as a baseline.
- `review-and-implementation-report-0.1.1.md` — candidate `0.1.1` repair evidence, unrun gates, and exact release blockers.
- `review-ai-context-tool-governance-0.1.2.md` — bounded AI/context/tool-governance review and deterministic evidence.
- `runtime-source-verification-2026-08-25.md` — current official Gemini Interactions/model, WooCommerce HPOS, WordPress REST, and Action Scheduler source verification with an explicit non-certification boundary.
- `runtime-source-verification-2026-08-24.md` — prior source review retained as a baseline.
- `proposal-analysis-and-release-report.md` — historical `0.1.1` implementation-to-proposal analysis retained as a baseline; current evidence is in the `0.1.7` report.
- `audit-dispositions.md` — manual disposition of heuristic audit signals.

## Machine-readable contracts

`../config/contracts/` contains JSON Schemas and registries for conversation state, features, tools, provider routing, and the proposal integrity record. Candidate `0.1.7` declares database schema `1.6.0` and Context Bundle wire schema `1.1.0`. It retains the immutable metadata-only actor-scoped manifest/read-back boundary and adds durable, bounded unresolved references to Conversation Focus. Provider-bound text is sanitized before contract/hash/attestation; response and semantic phases receive only registered recursively closed ToolResult projections; and the last transmission gate checks the byte-equivalent final request body. Catalog, knowledge, and recommendation projections are now closed list shapes, while continuations accept only validated provider-safe projections. Product-reference commands carry an exact versioned reference ID and product/variation tuple that the server rebinds to the actor-owned source message. Readiness remains separate and cannot certify release. Non-default/fallback routes are denied. The route remains Unconfigured with all five independent Context/privacy/result-projection/Woo/snapshot certification flags false because implementation is not live acceptance, guest Woo session binding is incomplete, and coherent post-mutation freshness is unresolved. The logical-tool catalog still contains all 155 proposal tools: one `tested`, seven `implemented_not_tested`, and 147 `contracted_not_implemented`; none is formally accepted. The local suite passes 27 PHP runner groups, 9 Node contracts, schema compilation/reference resolution, and the release verifier. Attributable CI for commit `c8a46aa73974af81fb1fb5e5831ab9b203cc3a43` additionally passes 9/9 quality jobs, three exact WordPress/WooCommerce/database cells, and 2/2 isolated Chromium/axe smokes. These are bounded automated results, not broad compatibility, provider, accessibility, security/privacy, operational, or formal acceptance. No commerce tool is certified, **0/35 anchors and 0/64 DoD items are accepted**, and the verdict remains **NOT READY**.

## Non-negotiable interpretation

- The AI leads semantic interpretation; regex, keywords, first-match selection, and fixed dialogue cannot substitute for it.
- The model proposes; server-side services authenticate, authorize, resolve, validate, confirm, execute, observe, and verify.
- WooCommerce and approved adapters own commerce truth.
- One foreground Conversation Focus controls ambiguous short replies; paused journeys cannot silently compete.
- Current-conversation continuity is mandatory. Durable Preference Memory is separate, optional, consented, and Off by default.
- Sensitive actions require a fresh exact preview, single-use confirmation, idempotency, concurrency control, and verified result.
- Historical customer-visible content is immutable; refreshed current state is separate.
- Optional modules stay absent from active UI, tools, routes, jobs, and claims until independently certified.
