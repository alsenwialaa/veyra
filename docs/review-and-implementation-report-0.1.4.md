# Veyra 0.1.4 trace, review, and implementation report

## Executive decision

- Whole-release verdict: **NOT READY**
- Engineering candidate: `0.1.4`
- Database schema: `1.4.0`
- Canonical proposal: v4.1
- Evidence date: 2026-08-24
- Formal acceptance: **0/35 canonical anchors accepted; 0/64 Definition of Done items accepted**
- Final deterministic verification: **304/304 PHP parse, 241/241 source-symbol load, 165 named PHP scenarios plus 2 whole-runner contracts, and 7/7 Node contracts passed**
- Package hashes: published outside the archives in the accompanying `Veyra-AI-Commerce-Agent-0.1.4-SHA256SUMS.txt` manifest to avoid a self-referential source-archive digest

This pass traced the `0.1.3` candidate through the requirements, context, recommendation, commerce-authority, privacy, and release-governance paths and implemented one connected, fail-closed state-binding slice. Requirements now have a dedicated actor-owned aggregate with integer resource versions, complete-history hashes, bounded values, exact source provenance, and compare-and-swap persistence. Recommendation filtering, ranking, diversification, and explanation no longer accept caller-authored requirement records or ranking scores; they load and recheck one exact server-owned requirement head.

The change is a material safety improvement, not production completion. No production semantic-promotion caller exists for requirement writes, recommendation output contracts have not received live WooCommerce or response-grounding certification, the runtime Context Bundle projection is not reconciled with a canonical closed context schema, deterministic response grounding remains incomplete, and no live WordPress/WooCommerce/MySQL/provider/browser/security matrix has been accepted.

## Canonical traceability disposition

All anchor and Definition of Done rows remain `Not assessed`. Implementation or focused deterministic evidence was added to relevant rows, but no row has the complete implementation, live test, documentation, owner, and named acceptance package required for promotion.

| Inventory | Candidate `0.1.4` state |
|---|---:|
| Canonical anchors | 35 total; 0 accepted |
| Definition of Done items | 64 total; 0 accepted |
| Logical-tool catalog | 155/155 design rows present |
| `tested` logical tools | 1 |
| `implemented_not_tested` logical tools | 7 |
| `contracted_not_implemented` logical tools | 147 |
| Formally accepted logical tools | 0 |
| Certified optional modules | 0 |

`context.get_runtime_clock` remains the sole `tested` logical tool. The seven `implemented_not_tested` rows are `requirements.get`, `requirements.propose_update`, `recommendation.retrieve_candidates`, `recommendation.apply_hard_filters`, `recommendation.rank`, `recommendation.diversify`, and `recommendation.explain`. Catalog governance continues to block provider discovery and execution for these seven rows. The remaining 147 rows are design contracts only.

## Connected requirement and recommendation slice

### Actor-owned requirement head

Schema `1.4.0` adds one `veyra_requirement_states` head per conversation. `RequirementState` carries the exact actor tuple, integer `resource_version`, SHA-256 state hash over the complete ordered criterion history, last source message, and timestamps. `WpdbRequirementStateRepository` loads only an exact actor-owned row and updates a non-empty head only when conversation, actor, actor hash, expected integer version, and expected state hash all match.

First-head creation uses one atomic `INSERT ... SELECT` boundary. The insert rechecks the exact owned conversation and an exact customer source message in the same SQL statement, then relies on the unique conversation key for a single winner. This closes the account-link/race window between an application ownership read and creation of the first requirement head. Database errors are distinguished from a clean compare-and-swap loss and fail closed.

`RequirementStateService` returns a separate integer resource version and state hash. Mutations require both expected values, an exact actor-owned customer message, an exact excerpt, and a bounded typed change set. Corrections preserve history and bidirectional supersession links. Recommendation operations load the current active projection, reject stale version/hash references before commerce evaluation, and recheck the same head after computation so changed requirements discard the advisory result.

### Upgrade provenance and legacy quarantine

The lazy `0.1.3` compatibility import no longer trusts a structurally valid memory record by itself. For every legacy criterion it resolves the source message through the exact actor and conversation, requires a customer-visible message, verifies byte offset and length, recomputes the excerpt hash, and checks any status-source message in the same actor scope.

Exact excerpt provenance does not prove that a historical field/operator/value was semantically entailed by the excerpt. Therefore every imported legacy `active` criterion is downgraded to `proposed`. It remains inspectable and exportable but is excluded from `active_requirements` and cannot silently drive recommendation evaluation. Malformed, cross-conversation, tampered, oversized, or invalid-graph legacy history fails closed without creating a head.

### Bounded state and closed requirement contracts

`RequirementCriterion` rejects unknown stored fields, bounds message identifiers and nested values, and applies a maximum of 256 value nodes in addition to depth, collection-size, key, and string limits. `RequirementState` limits history to 64 criteria, the canonical encoded history to 49,152 bytes, and the active provider projection to 24,576 bytes. It also validates unique active slots and supersession graph direction/linkage before hashing or persistence.

`RequirementsToolHandler` has closed input and successful-output schemas. The read returns complete history plus the active projection. The write requires separate resource-version and state-hash preconditions and remains `modelVisible=false` because semantic promotion is not composed.

### Exact recommendation binding

The five implemented recommendation operations remain advisory and non-mutating. Their closed input schemas accept bounded exact product IDs plus, where requirements are used, the expected requirement resource version and state hash. Caller-supplied requirement arrays and caller-supplied ranking scores are rejected.

`RecommendationOutputSchemas` now supplies closed successful data contracts for candidate retrieval, hard filtering, ranking, diversification, and explanation, plus closed stale-refresh alternatives for requirement-bound operations. The universal result validator exercises these schemas at the registry boundary, and governance accepts a typed union only when every alternative is itself a closed object. Focused tests pass every implemented success shape, the typed stale shape, and undeclared-field rejection. This is local contract evidence, not live commerce certification.

Filtering, ranking, and explanation operate only on the active server-owned requirement projection. Hard unsupported evidence fails closed. Compatibility remains unknown unless explicit approved evidence exists. Diversification now uses the exact server-ranked candidate snapshot rather than re-reading WooCommerce during diversity calculation, preventing mixed-snapshot scores and penalties.

Variable product parents are not exact commerce targets: they require a variation and are rejected by the hard-filter boundary with `exact_variation_required`. An exact variation is no longer rejected merely because it has a parent. Unknown soft evidence is explicitly unscored rather than treated as a mismatch or silently lowering the fit score, and structured explanation classifies it as `not_verified`.

### Context and privacy integration

`ContextBundleAssembler` adds one minimized requirement reference containing scope, resource version, state hash, and active criteria, and removes the retained legacy memory copy so the provider does not receive two competing requirement sources. This is exact state binding, not full canonical Context Bundle completion.

The WordPress privacy exporter includes dedicated requirement heads and also projects only the reviewed legacy `requirements` key for actor-owned conversations that have not yet been lazily imported. It does not expose the rest of `memory_json`. Requirement heads are included in erasure, uninstall inventory, and authenticated guest-to-account re-keying.

## Security and correctness fixes made during review

| Finding | Implemented disposition | Remaining boundary |
|---|---|---|
| Legacy records could be shape-valid but source-invalid | Exact actor/conversation customer-message, byte-range, and excerpt-hash verification | Semantic entailment still requires a production promotion service |
| Legacy active state could become recommendation authority | Imported active records are quarantined as `proposed` | A reviewed promotion/rejection workflow is absent |
| First insert could race an account-link ownership change | Atomic actor- and source-message-gated `INSERT ... SELECT` plus unique first-head CAS | Live MySQL/MariaDB race testing is pending |
| Privacy export could miss legacy requirements before lazy import | Actor-scoped, allowlisted legacy requirement projection added | Live WordPress exporter paging/volume review is pending |
| Nested values and context payloads could exhaust bounds | Node, depth, item, string, history-byte, and active-projection-byte budgets added | Whole canonical Context Bundle selection remains incomplete |
| Diversification mixed two Woo snapshots | Diversity consumes the server-ranked candidate snapshot | End-to-end Woo/policy snapshot versioning is not certified |
| Variable parents could appear exact while variations were overblocked | Parents require configuration; exact variations may remain eligible | Live variable/extension catalog matrix is pending |
| Unknown soft evidence behaved like a negative signal | Unknown weights are excluded from the denominator and explanations say `not_verified` | Merchant quality-policy acceptance is pending |
| Recommendation results lacked nested field enforcement | Closed per-operation success schemas and closed stale alternatives now run at the universal result boundary | Live WooCommerce, policy-race, response-grounding and named acceptance evidence are pending |

## Remaining release-blocking findings

### HIGH-01 — no production semantic-promotion caller exists

`requirements.propose_update` is intentionally hidden from the model. Focused tests invoke the handler through a manually controlled server-side harness, but the production runtime has no composed service that converts an AI proposal into a deterministic, semantically verified promotion and then invokes the write through the universal validation, audit, idempotency, and recovery boundary. Exact excerpt provenance alone is not semantic entailment.

Required closure: define the trusted promotion contract, evidence inputs, actor/feature/consent gates, audit record, replay identity, conflict/retry behavior, and production caller. Until then, requirement writes must remain unavailable to provider execution.

### HIGH-02 — recommendation contracts are closed locally but not certified end to end

The final tree publishes and enforces closed successful data schemas for all five implemented recommendation operations and closed stale-refresh alternatives for the four requirement-bound operations. Malformed top-level output is denied in the focused registry harness. These advisory structures still cannot authorize product claims or components, and there is no accepted live WooCommerce/extension matrix, published-policy race test, response-grounding projection, or named acceptance.

Required closure: live adapter and policy-freshness tests, exact result-code parity across failure paths, nested adversarial-output coverage, response-component/evidence projection, performance budgets, and named acceptance. The catalog therefore correctly remains `implemented_not_tested`.

### HIGH-03 — Context Bundle runtime and canonical schema remain mismatched

The runtime requirement projection is intentionally minimized to `scope`, `resource_version`, `state_hash`, `active_requirements`, and the durable-preference flag. The standalone `requirement-state.schema.json` describes a fuller aggregate, while the provider Context Bundle has no reconciled closed schema that validates this projection and every source/selection entry. The source manifest remains thinner than the canonical v4.1 per-item authority, classification, freshness, contradiction, redaction, purpose, and selection record.

Required closure: publish and enforce one canonical Context Bundle schema, define the exact minimized requirement-reference schema within it, validate every provider bundle before transmission, and implement deterministic per-section selection/truncation rather than failing the whole turn when a non-message section cannot fit.

### HIGH-04 — deterministic response grounding remains incomplete

Material no-tool prose can still evade the deterministic claim ledger, and the supplemental semantic verifier remains model judgment rather than a proof-carrying server boundary. Advisory recommendation results deliberately cannot authorize product claims or server-built product components.

Required closure: server-owned response intentions, a typed evidence catalog, deterministic prose/component composition or complete segment bindings, and denial of every material unsupported claim. A separately certified authoritative catalog read must ground product facts used in a recommendation response.

### HIGH-05 — no commerce tool is certified

The catalog still has only one tested clock read. Recommendation code can compute bounded advisory structures against Woo candidates, but no authoritative catalog/product read has closed output, live Woo evidence, response-component projection, and acceptance sufficient for certification. No advisory score can substitute for WooCommerce authority.

### HIGH-06 — mandatory continuity remains incomplete

The requirement aggregate closes one continuity gap. Conversation Memory beyond this projection, validated summaries, refusals, open loops, complete Journey State write/CAS/supersession, drift rebuild, source-erasure propagation, interruption/resume, and optional consented durable preferences remain incomplete or unaccepted.

### HIGH-07 — customer commerce workflows remain incomplete

Cart serialization, checkout-to-order placement, gateway handoff/recovery, owned-order action execution, CRM submission/decision/execution, offline-payment review decisions, and several confirmation/idempotency/reconciliation paths remain absent or intentionally blocked. Fail-closed behavior is not workflow completion.

### HIGH-08 — provider, privacy, live compatibility, and formal acceptance are absent

The checked-in provider route remains unconfigured and uncertified. No accepted live WordPress/WooCommerce/MySQL/MariaDB, HPOS, Store API, Blocks/classic, gateway, tax/shipping/fee, theme/extension, Gemini, browser/device, accessibility, Arabic/RTL, security, load/concurrency, backup/restore, rollout, rollback, upgrade, or uninstall matrix exists. Privacy/legal/transmission approval and named release ownership are unresolved.

## Verification status

The deterministic verification below was run against the frozen source tree used for packaging. It remains local/static evidence and does not replace any live or formal acceptance gate.

| Verification class | Status for this report |
|---|---|
| Repository PHP parse | 304/304 passed; syntax only |
| Source-symbol load | 241/241 passed; autoloadability only |
| Standalone PHP contract runners | 165 named scenarios passed plus the repository and rendering whole-runner contracts; 0 failed |
| Focused requirement/recommendation evidence | requirements 33/33; recommendation binding/output 8/8; legacy privacy 3/3; requirement repository runner passed |
| Official Composer/PHPUnit and dependency audit | Not run; Composer development dependencies are unavailable |
| Node UI/accessibility/security contracts | 7/7 passed; static contracts, not browser E2E |
| Static release verifier | Passed; version/schema/inventory/route/localization checks only |
| Veyra heuristic repository audit | 0 critical, 10 high, 31 medium; manually dispositioned heuristic signals, not an independent security audit |
| Reproducible installable/source packaging | Source archive 382 files; installable archive 285 files; byte-for-byte reproducibility checked; hashes in the external checksum manifest |
| Live WordPress/WooCommerce/MySQL/provider/browser matrices | Not run; release-blocking |

Focused evidence locations include:

- `src/Requirements/Domain/RequirementCriterion.php`
- `src/Requirements/Domain/RequirementState.php`
- `src/Requirements/Application/RequirementStateService.php`
- `src/Requirements/Infrastructure/WpdbRequirementStateRepository.php`
- `src/Requirements/Tool/RequirementsToolHandler.php`
- `src/Conversation/Application/ContextBundleAssembler.php`
- `src/Recommendation/Application/RecommendationService.php`
- `src/Recommendation/Tool/RecommendationToolHandler.php`
- `src/Recommendation/Tool/RecommendationOutputSchemas.php`
- `src/Privacy/WordPressPrivacyIntegration.php`
- `tests/Requirements/run-requirement-state-contract.php`
- `tests/Recommendation/run-recommendation-state-binding.php`
- `tests/Migration/run-requirement-state-repository.php`
- `tests/Migration/run-migration-contract.php`

These are source and deterministic-fixture references, not live production acceptance.

## Required next sequence

1. Compose and test the production semantic-promotion boundary for server-owned requirements.
2. Certify the five closed recommendation output contracts against live WooCommerce/policy races and a deterministic response-grounding projection while retaining their advisory classification.
3. Reconcile and validate the canonical Context Bundle schema and deterministic selection manifest.
4. Close deterministic response grounding with server-owned response evidence/composition.
5. Implement and individually certify an authoritative `catalog.get_product` read and product-component projection.
6. Complete continuity and commerce write workflows with confirmation, idempotency, locking, reconciliation, privacy, and audit.
7. Run every live compatibility, provider, security, privacy, accessibility/localization, performance, migration/recovery, rollout, and rollback matrix.
8. Freeze artifacts, rerun final deterministic verification, publish reproducible hashes, and obtain named acceptance for every anchor and DoD row.

## Final release decision

Candidate `0.1.4` materially improves customer isolation, requirement provenance, concurrency, boundedness, recommendation state binding, and upgrade privacy. It does not provide a complete production commerce agent.

**Final verdict: NOT READY. Do not deploy this candidate to production.**
