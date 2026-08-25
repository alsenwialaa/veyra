# Veyra release evidence

## Release verdict

- Verdict: **NOT READY — do not deploy to production**
- Artifact: engineering candidate `0.1.7`
- Database schema: `1.6.0`
- Context Bundle wire schema: `1.1.0`
- Canonical proposal: v4.1, SHA-256 `44baae2afb053580028c2d8ae3372669c0a8d71d5a2c4990f899ef9d8b51b95b`
- Evidence update: 2026-08-25
- Formal acceptance: **0/35 anchors; 0/64 Definition of Done items**
- Formally accepted tools: **0**
- Release owner/acceptance authority: unresolved

Candidate `0.1.7` is a fail-closed engineering build, not a production release. The checks below establish deterministic/static evidence plus bounded attributable quality checks and platform/browser smokes. They do not establish broad supported WordPress/WooCommerce/database/provider behavior, privacy/legal approval, independent security or accessibility acceptance, performance, operations, or formal proposal acceptance.

## Inventory and governance

| Registry/surface | State | Release meaning |
|---|---:|---|
| Canonical logical tools | 155 | Complete design inventory only |
| `tested` | 1 | `context.get_runtime_clock`; no commerce tool |
| `implemented_not_tested` | 7 | Governance-blocked requirement/recommendation rows |
| `contracted_not_implemented` | 147 | Not eligible for provider discovery/execution |
| Formally accepted tools | 0 | No named acceptance |
| Canonical anchors | 0/35 accepted | Every row remains `Not assessed` |
| Definition of Done | 0/64 accepted | Every row remains `Not assessed` |
| Optional modules certified | 0/17 | Remain Off until separate certification |

No catalog row, anchor, DoD item, provider route, commerce workflow, or optional module was promoted by this review.

## Bounded repairs implemented in 0.1.7

### Lifecycle, persistence, and continuity

- Schema `1.6.0` adds `conversation_focus.unresolved_references_json` through an idempotent, postcondition-checked migration; clean-install DDL matches the upgrade shape.
- Conversation Focus enforces exact actor ownership, compare-and-set row count, 36-character journey-ID storage width, at most ten unique opaque unresolved references, canonical JSON, and fail-closed read-back.
- Bootstrap/runtime failures block Veyra without breaking the storefront; service composition precedes hook publication and rolls back partially registered security/privacy/media hooks.
- Deactivation transactionally invalidates pending confirmations, marks in-progress idempotency records uncertain, then releases locks. Uninstall preserves recovery roles when deletion fails.
- WordPress privacy callbacks return `WP_Error` for authorization, audit, or query failure and do not report a failed page as complete.

### Commerce writes and result truth

- Cart and checkout use the same actor-wide Woo authority lock. Cart inspects state before claiming idempotency and again after lock acquisition; retry requires authoritative reconciliation and uncertain results do not become blind retries.
- Checkout persists retry safety and contains current-state/reconciliation exceptions. Confirmation, CRM, payment-review, and REST idempotency terminal-transition failures become uncertain rather than false success.
- Orders apply stricter owned-order/status checks and reject arbitrary shipment-filter data until a typed adapter exists. CRM rejects empty messages before idempotency claim and excludes checkout drafts from customer order links. Payment review validates transfer timestamps strictly.
- Recommendation quantity truth uses current minimum, maximum, stock, backorder, and sold-individually constraints rather than treating any positive quantity as satisfied.

### Provider, contracts, knowledge, and catalog

- Failed readiness preserves the actual provider error and cannot report credentials, structured output, or text as available; non-readiness native Gemini calls are rejected.
- Provider continuations can be constructed only from a validated provider-safe projection. Semantic replay no longer mutates the registered ToolResult payload.
- Provider-visible schemas are checked recursively, including nested arrays/objects; dynamic maps fail closed.
- Catalog, knowledge, and recommendation handler outputs use closed list-shaped attributes/facets and explicit count/truncation/completeness fields. Exact comparison/variation IDs are validated without first-result selection.
- The checked-in Ajv Draft 2020-12 runner compiles registered schemas and resolves their references; 19 `x-invariant` annotations remain dependent on their mapped runtime tests.

### Protected media and customer experience

- Media routes require an explicit absolute non-public `VEYRA_PROTECTED_STORAGE_PATH`, approved scanner callback, and explicit `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS` from 3,600 through 31,536,000 seconds. No retention default exists.
- Authorized delivery verifies the exact stored byte count and SHA-256, caps the verified payload at 10 MiB, uses process memory, and never streams unverified content.
- The customer UI rejects cross-origin REST bases, binds quick replies to the exact pending question, and gives the native confirmation dialog focus/Escape behavior. The isolated Chromium fixture passes keyboard/dialog/Escape, 390×844 viewport containment, and its serious/critical axe gate; broader accessibility acceptance remains open.

The complete subsystem and 20-feature trace is in `review-and-implementation-report-0.1.7.md`.

## Provider route remains deliberately non-transmitting

The executable default route remains `Unconfigured`. These values remain false:

- `shopper_transmission_enabled`
- `privacy_policy_published`
- `evaluation_passed`
- `release_certified`
- `context_manifest_persistence_certified`
- `prohibited_data_filter_certified`
- `provider_result_projection_certified`
- `woocommerce_actor_binding_certified`
- `context_snapshot_consistency_certified`

Implementation and fixture evidence cannot self-promote independent certification flags.

## Verification summary

| Check | Result | Evidence boundary |
|---|---|---|
| Standalone PHP runner groups | 27/27 passed | Dependency-light local runtime |
| Named domain scenarios | 244 passed, 0 failed | Count-bearing deterministic runners |
| Auxiliary PHP suites | Passed | Provider-safe projection, requirement repository, rendering, source-symbol sweep |
| Provider transmission | 13/13 passed | Fakes; no live provider call |
| Source-symbol load | 262/262 | Load/syntax evidence only |
| Node contracts | 9/9 passed | UI/accessibility/security source contracts, not browser acceptance |
| Draft 2020-12 contracts | 26 schemas compiled | Registered references resolved; 19 extension invariants inventoried |
| Registries | 37 features; 155 tools | Inventory/shape validation only |
| Static release verifier | Passed | `0.1.7`, schema `1.6.0`, 28 capabilities, 20 core, 17 optional, 30 JSON, 262 PHP source, 12 REST routes, 91 Arabic strings |
| Workflow YAML and diff check | Passed | Static definition/whitespace boundary |
| Heuristic audit | 0 critical, 16 high, 34 medium | Manually dispositioned; not an independent security audit |
| Composer/PHPUnit, PHPStan, Plugin Check, coverage | Passed in attributable CI | Bounded automated evidence; Clover generation has no accepted coverage threshold |
| WordPress/WooCommerce/database smoke | 3/3 passed in attributable CI | Exact three-cell matrix below; not a broad compatibility declaration |
| Isolated Chromium/axe smoke | 2/2 passed in attributable CI | One fixture; not WCAG, assistive-technology, RTL, or workflow acceptance |
| Live provider and broad acceptance matrices | Not run / Not assessed | Release-blocking |
| Deterministic package double-build | Passed | Two byte-identical builds; 425-file source and 275-file installable archives |

The standalone scripts ran through a PHP 8.2.32 WebAssembly runtime. Attributable CI also ran the deterministic contract suite on PHP 8.1, 8.2, 8.3, and 8.4. That is bounded runtime evidence, not a complete supported-PHP declaration.

## Attributable GitHub Actions evidence

Evidence commit: [`c8a46aa73974af81fb1fb5e5831ab9b203cc3a43`](https://github.com/alsenwialaa/veyra/commit/c8a46aa73974af81fb1fb5e5831ab9b203cc3a43).

| Workflow | Result | Exact evidence |
|---|---:|---|
| [Candidate quality checks 32797874762](https://github.com/alsenwialaa/veyra/actions/runs/32797874762) | 9/9 jobs passed | Composer validation/install/audit and all PHP/Node contracts on PHP 8.1–8.4; PHPUnit coverage generation; PHPStan; Draft 2020-12 schemas/registries; Plugin Check; deterministic packaging |
| [WordPress and WooCommerce integration 32797874806](https://github.com/alsenwialaa/veyra/actions/runs/32797874806) | 4/4 jobs passed | Three packaged platform cells plus isolated Chromium widget/accessibility smoke |

Plugin Check completed with **0 errors and 0 warnings**. The generated Clover artifact reports coverage across 262 PHP source files and 222 classes: 2,892/21,277 statements (13.59%), 158/1,355 methods (11.66%), and 3,050/22,632 elements (13.48%). Coverage-report generation passed; no release coverage threshold is approved or claimed.

The deterministic package job built the candidate twice byte-for-byte: source archive 425 files, SHA-256 `64c814e085407b49403a348fdda23c9d1994e1878a9fb612d252af58a77ae7a9`; installable archive 275 files, SHA-256 `4bf60ab538ed4739ce7b4dd2a24e8c5514ff6a7a52eb4be21672e9ea5ee1e077`.

| Platform cell | Result | Bounded behavior exercised |
|---|---:|---|
| WP 6.5 / WC 8.5.2 / PHP 8.1 / MySQL 8.0 | Passed | Packaged activation/reactivation; public simple-product/order CRUD under legacy then HPOS storage; classic and Checkout Blocks page-fixture assertions; fatal-log denial |
| WP 7.1 / WC 11.0.1 / PHP 8.4 / MySQL 8.0 | Passed | Same bounded packaged CRUD and page-fixture smoke on the current cell |
| WP 7.1 / WC 11.0.1 / PHP 8.4 / MariaDB 11.4 | Passed | Same bounded packaged CRUD and page-fixture smoke on MariaDB |
| Chromium / WP 7.1 / WC 11.0.1 / PHP 8.4 / MySQL 8.0 | 2/2 passed | Exact asset/bootstrap/mount checks, keyboard/dialog/Escape focus, 390×844 viewport containment, and zero serious/critical axe violations in the isolated fixture |

These are ephemeral automated smokes, not complete commerce E2E, upgrade/rollback/concurrency/load evidence, a compatibility or HPOS declaration, WCAG/assistive-technology/RTL acceptance, independent security/privacy acceptance, operational readiness, or live Gemini transmission/evaluation. They promote no formal ledger row.

## Mandatory blockers

| Gate | Current state |
|---|---|
| Retention/privacy operations | Context Bundle has no approved retention policy; protected media requires an explicit bounded deployment value, but legal basis, start events, holds, deletion propagation, processors, and approvals remain open |
| Provider route | Unconfigured; no release-selected model, privacy publication, live evaluation, or independent route certification |
| Snapshot consistency | No accepted coherent multi-source transaction/version model or general post-mutation refresh/rebinding |
| Guest Woo binding | Missing; guest commerce remains denied |
| Tool coverage/certification | 1 tested, 7 implemented-not-tested, 147 contracted; 0 formally accepted |
| Commerce/continuity workflows | Catalog/cart/checkout/order/gateway/CRM/payment/media/knowledge/memory/summary/handoff/operations remain incomplete end to end |
| Live compatibility | The exact three-cell packaged smoke passed; broader WordPress/WooCommerce/HPOS/Blocks/classic, Store API, extensions, databases, Action Scheduler, gateways, shipping/tax/fees remain unaccepted |
| Security/accessibility/localization | The isolated Chromium/axe smoke passed; no independent security acceptance or accepted assistive-tech/zoom/reflow/Arabic/RTL/full-flow matrix |
| Performance/operations/recovery | No accepted load/concurrency, monitoring, incident, backup/restore, rollout, or rollback exercise |
| Formal acceptance | 0/35 anchors and 0/64 DoD items accepted; accountable owners unresolved |

Any mandatory `Missing`, `Not run`, `Not assessed`, or failed gate blocks release.

## Final decision

Candidate `0.1.7` is materially safer and more testable than `0.1.6`, and its bounded automated quality/platform/browser smokes pass. Broad compatibility, provider/privacy/evaluation, complete workflows, operational readiness, and formal acceptance remain absent.

**Final verdict: NOT READY. Do not deploy this candidate to production.**
