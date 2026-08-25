# Veyra 0.1.7 subsystem trace, repair, and release review

- Assessment date: 2026-08-25
- Canonical authority: Veyra Production Proposal v4.1
- Canonical SHA-256: `44baae2afb053580028c2d8ae3372669c0a8d71d5a2c4990f899ef9d8b51b95b`
- Candidate: `0.1.7`
- Database schema: `1.6.0`
- Context Bundle wire schema: `1.1.0`
- Work mode: repository completion, defect repair, and release audit
- Verdict: **NOT READY — do not deploy to production**

## Executive decision

The supplied `0.1.6` source was treated as untrusted implementation evidence and traced across all 25 source domains, the 20 Production Core features, the 17 optional modules, the 155-tool registry, all 35 canonical anchors, all 64 Definition of Done items, customer/admin assets, persistence and lifecycle code, contracts, tests, packaging, and CI definitions.

Candidate `0.1.7` repairs confirmed fail-closed, state-integrity, concurrency, privacy, provider, and experience defects. The strongest changes are:

1. schema `1.6.0` durably preserves bounded Conversation Focus unresolved references with actor-owned compare-and-set/read-back validation;
2. bootstrap, runtime composition, deactivation, uninstall, and privacy callbacks contain failures and do not publish partial success;
3. cart and checkout share one actor-wide authority lock, reconcile before retry, preserve retry safety, and map uncertain transitions truthfully;
4. protected media requires explicit non-public storage, an approved scanner, and explicit bounded retention, and delivery verifies exact bytes and SHA-256 before returning an in-memory stream;
5. provider readiness preserves the real provider failure, provider continuations accept only validated safe projections, and nested provider-visible schemas are recursively closed;
6. catalog, knowledge, and recommendation projections remove dynamic maps/first-result ambiguity, while recommendation quantity checks use current minimum, maximum, sold-individually, and stock constraints;
7. order, CRM, payment-review, confirmation, idempotency, REST, and quick-reply paths received focused failure/ownership/replay repairs; and
8. a checked-in test environment now defines deterministic PHP/Node/schema/static/package jobs plus WordPress/WooCommerce/MySQL/MariaDB and browser/axe matrices.

These repairs do not establish production completeness. The provider route remains `Unconfigured`; shopper transmission, privacy publication, evaluation, release certification, and all five independent route certifications remain false. Live platform/provider/browser results, formal acceptance, and major canonical workflows are still absent.

## Formal acceptance ledger

| Inventory | Evidence-backed state | Release meaning |
|---|---:|---|
| Canonical anchors | **0/35 accepted** | Every row remains `Not assessed` |
| Definition of Done | **0/64 accepted** | Every row remains `Not assessed` |
| Logical tools catalogued | 155/155 | Design inventory, not implementation proof |
| `tested` | 1 | `context.get_runtime_clock`; not a commerce tool |
| `implemented_not_tested` | 7 | Two requirements and five recommendation rows; governance-blocked |
| `contracted_not_implemented` | 147 | Not eligible for discovery or execution |
| Formally accepted tools | **0** | No named acceptance authority/evidence |
| Certified optional modules | **0/17** | All remain Off and uncertified |

No row was promoted because a class, schema, fixture, or workflow definition exists. `UniversalToolGovernance` continues to deny provider discovery/execution unless catalog status and all runtime gates permit it.

## Source subsystem trace

| Source domain | Review and 0.1.7 change | Remaining boundary |
|---|---|---|
| `AI` | Preserved provider error codes; false readiness capabilities no longer become true. Non-readiness native Gemini calls are denied. Provider continuations accept validated projections only; semantic replay is recorded without mutating stored ToolResult data; nested output objects/arrays must be recursively closed. | Default route Unconfigured; exact release model, privacy, live capability/evaluation, complete projection acceptance, and post-mutation context refresh unresolved. |
| `Audit` | Expanded safe-metadata denial for bank identifiers and retained minimum correlation-only failure evidence. | Independent log/secret review, access/retention policy, and live observability unassessed. |
| `Bootstrap` | Deferred migration work instead of request-path schema work; plugin/runtime failures block Veyra without breaking Woo. Security/privacy/media hook registration rolls back as one unit. Deactivation transaction invalidates confirmations, marks in-progress idempotency uncertain, then releases locks. Uninstall retains recovery roles when deletion fails. | Live clean/repeated upgrade, interruption/resume, rollback, deactivation/reactivation, bounded purge, and time-limit behavior unrun. |
| `CRM` | Empty customer messages fail before idempotency claim; checkout-draft orders cannot be attached; failed terminal idempotency transitions become uncertain. | Case taxonomy, teams, SLAs, human workflow, live duplicate/race behavior, and decision-to-execution adapters unresolved. |
| `Cart` | Uses the same actor-wide Woo authority lock as checkout; inspects current state before claim and after lock; safe retry reconciles before a new mutation; uncertain outcome does not become retryable success. | Live Woo sessions, totals/extensions, compound semantics, guest binding, concurrent tabs, and accepted idempotency evidence missing. |
| `Catalog` | Added closed outputs for current handler surfaces, list-shaped facets/attributes, exact typed comparison IDs, strict variation attributes, and explicit truncation/completeness metadata. | Catalog tools remain unaccepted; live variable-product, visibility, pricing, stock, extension, and response-grounding evidence absent. |
| `Checkout` | Shares cart authority lock; persists retry safety; contains current-state and reconciliation failures; failed idempotency terminal transitions become uncertain. | Persistent end-to-end checkout, shipping/tax/fee/gateway parity, Blocks/classic, callbacks, and order creation unaccepted. |
| `Confirmation` | Bounded/canonical confirmation request fields; unexpected sensitive-gate failure maps to uncertain rather than silently safe retry. | Database atomicity and complete sensitive-action E2E matrix unrun. |
| `Context` | Reviewed authority/freshness helpers and retained server-side time/authority boundaries. | Complete branch/location/culture/time source publications and invalidation evidence missing. |
| `Conversation` | Schema 1.6 stores `unresolved_references_json`; journey IDs match the 36-character column; unresolved references are unique/bounded; reads/writes are actor-owned, versioned, and round-trip checked. Pending-question and quick-reply bindings are exact. | Validated summaries, full Journey State/history, cross-session resume, natural-language thresholds, and live isolation remain incomplete. |
| `Experience` | Cross-origin REST bases are rejected; quick replies are filtered to the exact pending-question binding; confirmation dialog uses native focus/Escape behavior; REST idempotency exceptions are contained. Browser fixtures cover mobile, keyboard, focus, and axe smoke paths. | Browser workflow was configured but not recorded as run here; WCAG 2.2 AA, screen reader, zoom/reflow, Arabic/RTL, reconnect, and historical-flow acceptance missing. |
| `Features` | Effective-state schema requires non-empty reason/remediation when blocked/degraded; missing Woo/runtime failures no longer appear On or falsely equivalent. | Every Production Core feature remains registry `Blocked`; cross-surface live exposure matrix unassessed. |
| `Http` | Reviewed request/error envelope helpers as adapter-only boundaries. | Complete route-by-route nonce/capability/ownership/rate/abuse integration evidence missing. |
| `Identity` | Existing server-resolved actor/capability scope retained and exercised by affected fixtures. | Guest-to-Woo-session binding, complete cross-customer matrix, account-link transaction behavior, and role-grant policy unresolved. |
| `Infrastructure` | Added postcondition-checked schema 1.6 focus migration; actor-scoped repository failures throw instead of becoming ordinary misses. | Live `dbDelta`, MySQL/MariaDB isolation/index/volume, rollback, backup/restore, and queue operations unrun. |
| `Knowledge` | Inputs now use closed typed source-ID/source-type lists; outputs report total, truncation, completeness, and no silent first-result selection; conflict result is advisory. | Published source operations, conflicts, citations, injection corpus, freshness, and provider grounding unaccepted. |
| `Media` | Protected routes require explicit absolute non-public storage, scanner, and `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS`; no guessed default. Delivery verifies exact size/SHA-256 up to 10 MiB in `php://memory` and never streams unverified data. | Storage/scanner vendor, malware/polyglot/parser corpus, encryption policy, live access, deletion propagation, and legal retention approval missing. |
| `Operations` | Runtime/admin asset versions align to `0.1.7`; configuration remains capability/effective-state gated. | Five merchant products, simulation/publish/schedule/rollback, view-as-customer, queues, monitoring, and incident workflows incomplete. |
| `Orders` | Tightened status/ownership checks and blocks arbitrary shipment-filter data until a typed adapter exists. | Customer Action Matrix, amendment recalculation/locks, financial routes, tracking adapters, and live HPOS behavior unaccepted. |
| `PaymentReview` | Strict transfer timestamp validation; repository/idempotency transition failure becomes uncertain; verified media never spills to a generic temporary file. | Approved offline methods/evidence/transition policy, staff queues, live Woo status effects, and review E2E missing. |
| `Privacy` | WordPress callbacks return `WP_Error` on authorization, audit, or query failure and never mark a failed page complete; focus unresolved references are inventoried; referenced attachments are retained when required. | Legal basis, paging under real WordPress, complete export/erasure propagation, holds, source deletion, processor deletion, and approval unresolved. |
| `Recommendation` | Attributes are closed ordered lists; hard quantity truth uses current min/max/stock/backorder/sold-individually facts; caller scores/requirements and stale state remain denied. | Five registry rows remain `implemented_not_tested`; live Woo/policy race, compatibility claims, diversity/evaluation thresholds, and acceptance missing. |
| `Requirements` | Existing actor-owned version/hash CAS and exact source provenance were rechecked against provider/recommendation changes. | Semantic promotion, qualified AI evaluation, live repository behavior, complete correction/resume acceptance incomplete. |
| `Runtime` | Computes truthful compatibility/health; composes services before hooks and removes registered hooks if later registration fails; protected media is exposed only when all explicit gates pass. | Live WordPress hook ordering, dependency loss/recovery, Action Scheduler, caches, and production diagnostics unrun. |
| `Shared` | Canonical JSON, clock, identifiers, and value boundaries remain the shared deterministic base. | Full static-analysis/coverage results and performance budgets are not accepted. |

## Production Core feature trace

The registry truth is unchanged: every Production Core entry has initial effective state `Blocked`, even when bounded source code exists.

| Feature key | Bounded evidence | Certification gap |
|---|---|---|
| `ai_semantic_orchestration` | Typed decision/response phases, bounded executor, strict provider gate | Unconfigured route; no exact-route live evaluation or end-to-end acceptance |
| `ai_context_graph` | Bounded Context Bundle/source accounting and authority metadata | Full graph contradiction/invalidation/source-deletion and live isolation incomplete |
| `ai_conversation_focus` | Schema 1.6 focus/reference persistence and exact pending-question binding | Natural short-reply, paused-journey, cross-session, and sensitive-binding thresholds unaccepted |
| `ai_merchant_knowledge` | Closed bounded retrieval/read/conflict contracts | Publication operations, source policy, live grounding, and conflict acceptance missing |
| `ai_conversation_memory` | Requirement state, focus, manifest, and bounded current-turn continuity | Summaries, refusals/open loops/checkpoints, cross-session resume, and drift handling incomplete |
| `ai_cultural_profiles` | Schema/design only | Language/dialect profiles and qualified human acceptance absent |
| `ai_location_awareness` | Bounded location-presence context only | Source precision, consent, branch/tax/shipping behavior, and market approval absent |
| `ai_time_awareness` | `context.get_runtime_clock` is the one catalog-tested tool | Store/branch calendars, cutoffs, holidays, locale behavior, and acceptance absent |
| `ai_multimodal_understanding` | Protected upload/access and modality-presence contracts | Voice/image/document interpretation, scanner/parser/provider, corrections, and accessibility unaccepted |
| `ai_proactive_next_action` | Registry/contract design | No certified proactive workflow, policy, or evaluation |
| `ai_human_handoff` | Handoff types/scaffolding | Queue, presence/SLA, assignment, takeover/resume, and operations evidence missing |
| `commerce_product_assistance` | Requirements/recommendation/catalog bounded handlers and exact references | Seven related rows governance-blocked; live Woo grounding and acceptance absent |
| `commerce_cart` | Actor-owned Woo handler with shared lock/idempotency/reconciliation repairs | Live Woo session/totals/concurrency/extension matrix and accepted workflow absent |
| `commerce_chat_checkout` | Persistent preview/state handler with shared authority lock | End-to-end shipping/tax/payment/gateway/order flow and Blocks/classic acceptance absent |
| `commerce_order_service` | Owned order reads and safer status/adapter boundaries | Action Matrix, amendments, refunds/payments/tracking, HPOS live acceptance absent |
| `service_crm` | Bounded case tool, idempotency, and checkout-draft exclusion | Approved case operations, staff workflow, queue, and live integration absent |
| `payment_offline_review` | Protected evidence/review state separation and failure containment | Merchant payment policy, exact Woo transition matrix, staffing, and live acceptance absent |
| `chat_message_quoting` | Historical rendering/reference contracts | Full source deletion/redaction, cross-session, browser, and accessibility evidence absent |
| `chat_product_references` | Exact actor-owned reference ID/product/variation rebinding | Live stale/current rendering and Woo compatibility acceptance absent |
| `operations_human_console` | Capability/configuration products and safe runtime health | Complete agent/context/experience/commerce/operations consoles and rollout evidence absent |

All 17 optional modules remain Off and uncertified: `shopper_guest_checkout`, `shopper_saved_shortlist`, `shopper_delivery_preview`, `shopper_persistent_comparison`, `shopper_recommendation_tuning`, `shopper_address_autocomplete`, `shopper_review_summaries`, `shopper_product_alerts`, `shopper_guided_bundles`, `shopper_gift_mode`, `shopper_reorder_subscriptions`, `shopper_post_purchase`, `shopper_returns_exchange`, `shopper_loyalty_rewards`, `shopper_shareable_decisions`, `ai_customer_preference_memory`, and `ai_spoken_responses`.

## Protected-media deployment contract

Media routes remain Blocked unless all of these are true:

- `VEYRA_PROTECTED_STORAGE_PATH` is an explicitly configured absolute path outside known public/document/upload roots;
- `veyra_malware_scanner_callback` supplies an approved callable scanner; and
- `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS` is explicitly set to an approved integer from `3600` through `31536000`.

There is no retention default. The bounded range is an implementation safety limit, not merchant/legal approval of a duration. `DEC-017`, `DEC-018`, and `DEC-023` remain open.

## Local verification

| Check | Result | Evidence boundary |
|---|---:|---|
| Standalone PHP runner groups | 27/27 passed | Dependency-light local runtime, not Composer orchestration |
| Count-bearing domain scenarios | 242 passed, 0 failed | Deterministic fakes/contracts |
| Auxiliary PHP suites | Passed | Provider-safe projection, requirement repository, rendering, source-symbol sweep |
| Source-symbol load | 262/262 | Load/syntax boundary, not runtime integration |
| Provider transmission | 13/13 | Fake/repository boundary; no live Gemini request |
| Node UI/accessibility/security contracts | 9/9 | Static/DOM contract assertions, not browser acceptance |
| Draft 2020-12 schemas | 26 compiled | Registered references resolved; 19 `x-invariant` annotations need mapped runtime assertions |
| Registries | 37 features; 155 tools | Inventory/shape validation only |
| Release verifier | Passed | `0.1.7`, schema `1.6.0`, 28 capabilities, 20 core, 17 optional, 30 JSON, 262 PHP source, 12 REST routes, 91 Arabic strings |
| Workflow YAML and diff whitespace | Passed | Definition/static validity only |
| Heuristic repository audit | 0 critical, 16 high, 34 medium | Manual dispositions; not independent security certification |
| Deterministic package double-build | Passed | Two byte-identical builds; 422-file source and 306-file installable archives |

The local PHP runner is a PHP 8.2.32 WebAssembly environment. The candidate requires PHP 8.1+, so the PHP 8.1–8.4 support matrix is not established by this run.

Composer/PHPUnit, PHPStan, Plugin Check, coverage, live WordPress/WooCommerce/MySQL/MariaDB, Playwright/axe browser execution, and GitHub Actions are **Not run in this report** unless a later attributable result is appended. Checked-in workflow files are a test environment, not proof that the jobs passed.

## Release blockers

1. All 35 anchors and all 64 DoD items lack formal acceptance; the release owner/acceptance authority is unresolved.
2. The tool registry remains 1 tested, 7 implemented-not-tested, 147 contracted-not-implemented, and 0 accepted; no commerce tool is certified.
3. The Gemini route is Unconfigured with no release-selected exact model, published privacy policy, live evaluation, or certified result/context/Woo/snapshot boundary.
4. Context Bundle and protected-media retention/legal-hold/source-deletion/processor-deletion policy and operations remain open.
5. Guest-to-Woo-session binding and complete cross-customer/account-link/live database isolation evidence are missing.
6. No coherent multi-source transactional snapshot and general post-mutation refresh/rebinding model is accepted.
7. Catalog, cart, checkout, orders, gateways, CRM, payment review, media, knowledge, memory/summaries, handoff, and operations are incomplete as canonical end-to-end workflows.
8. Supported WordPress/WooCommerce/PHP/database/extensions/gateways/themes matrix, HPOS declaration, Blocks/classic parity, performance, recovery, and operations exercises are not accepted.
9. Browser WCAG 2.2 AA, assistive technology, zoom/reflow, mobile, Arabic/RTL/mixed-direction, and qualified cultural review are not accepted.
10. Independent security/privacy review, malicious media corpus, load/concurrency evidence, backup/restore, rollout, rollback, and incident ownership are missing.

## Final verdict

Candidate `0.1.7` is a safer and more testable engineering candidate than the supplied `0.1.6` source. It remains incomplete under the canonical proposal and has no formal release acceptance.

**NOT READY. Do not deploy it to production, do not enable shopper provider transmission, and do not declare HPOS or optional-module compatibility.**
