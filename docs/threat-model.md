# Veyra threat model

Status: design-time threat model, 2026-08-24. Verification and residual-risk acceptance are **Not assessed**.

## Scope and assets

Scope covers the Production Core architecture, all proposal-defined data/contracts, and the dormant boundaries of optional modules. Assets include actor/session identity, conversations and memory, product/cart/checkout/order state, confirmations/idempotency/locks, CRM/payment-review records, protected files and derived observations, provider credentials/routes, merchant configuration/knowledge, audit/evaluation evidence, migrations, queues, and release artifacts.

Out of scope for this design review—but mandatory before release—are the exact hosting/network topology, selected processors/regions, gateway/extension adapters, malware scanner, production key management, legal basis, penetration test, and operational ownership.

## Trust boundaries

1. Browser/mobile webview ↔ WordPress REST/Store API.
2. Secure guest session ↔ authenticated account linking.
3. Customer resource scope ↔ another customer, staff assignment, or public data.
4. WordPress adapters ↔ application/domain services.
5. Model/provider output ↔ authorized tool registry.
6. Veyra state ↔ WooCommerce current commerce authority.
7. WordPress ↔ external Gemini/speech/vision/map/storage/notification services.
8. Upload ingress ↔ protected storage/scanner/parser/analysis.
9. Queue/job/webhook/CLI ↔ current policy and authoritative resources.
10. Staff console ↔ customer identity/content/files/decisions/execution.
11. Published configuration ↔ draft/imported/untrusted configuration.
12. Current state ↔ immutable historical customer-visible snapshots.

## Threat and abuse-case register

| ID | Threat / failure path | Impact | Required controls | Required proof | Residual status |
|---|---|---|---|---|---|
| TM-001 | Client/model supplies another customer's conversation, cart, order, case, review, preference, or attachment ID | Cross-customer disclosure or mutation | Server-resolved actor; repository scope predicates; ownership resolver at every boundary; no ID as authority | Systematic IDOR tests for every resource and cache key | Not assessed |
| TM-002 | Guest token guessed, fixed, replayed, or linked to the wrong account | Session takeover or state theft | High entropy; digest storage; rotation/expiry; SameSite/secure transport; authenticated link transaction and revalidation | Entropy/fixation/replay/link-conflict tests | Not assessed |
| TM-003 | Nonce or logged-in status treated as authorization | Capability/ownership bypass | Nonce only for CSRF; explicit permission callback, capability, ownership, feature and state checks | Negative REST/AJAX/Store API tests | Not assessed |
| TM-004 | Role name grants broad staff/customer authority | Identity/files/message/decision/execution escalation | All 28 independent capabilities; assignment and resource scope; reauthorization at execution | Per-capability grant/deny matrix tests | Not assessed |
| TM-005 | Product/review/knowledge/document text instructs model to expand tools or reveal data | Prompt/tool/context injection and exfiltration | Content/data separation; fixed server registry; typed schemas; authorization; output verification; data minimization | Adversarial injection scenario pack | Not assessed |
| TM-006 | Malformed provider output, extra fields, unknown tool/version, or provider-specific object reaches domain | Arbitrary action or incorrect state | Provider adapter terminates raw response; strict additional-properties false; safe rejection/repair bounds | Contract fuzz/malformed-output tests | Not assessed |
| TM-007 | Hidden regex/keyword/first-match fallback routes intent during provider outage | Wrong action represented as AI | No semantic fallback; provider Blocked/declared Degraded only; static/adversarial scans | Outage and semantic adversary tests | Not assessed |
| TM-008 | “Yes”, “two”, “same”, or a quote binds to stale/wrong question/resource | Unauthorized or incorrect write | One foreground Focus; versioned Pending Question; explicit-reference priority; answer-schema/resource/freshness validation | Multiple-journey, stale-focus, wrong-resource, sensitive-binding tests | Not assessed |
| TM-009 | Silence, background audio, OCR, image/QR, quote, or product card acts as confirmation | Unauthorized sensitive action | Media/quotes/references are context only; exact confirmation record and visible prompt binding | Media-never-confirms and replay tests | Not assessed |
| TM-010 | Confirmation reused after price/stock/contact/shipping/payment/state change | Stale financial/action authorization | Canonical state hash, dependency versions, short expiry, invalidation graph, atomic single use | All material-invalidation tests | Not assessed |
| TM-011 | Duplicate tap/tab/model retry/job/callback creates duplicate order, refund, case, evidence, subscription, or redemption | Financial/commerce harm | Canonical idempotency scope; same-key/different-payload conflict; locks/version checks; callback dedupe | Parallel/race/reorder/lost-response tests | Not assessed |
| TM-012 | Timeout occurs after external write; retry executes again | Duplicate or fabricated outcome | Durable in-progress/uncertain state; query authority before retry; reconciliation; safe retry mapping | Timeout before/after side effect and lost response tests | Not assessed |
| TM-013 | AI streams or composes success before backend result | Fabricated success | Block success text until verified operation result; evidence/verification pass; immutable result reference | Forced backend failure after model proposal | Not assessed |
| TM-014 | Direct order storage or custom totals diverge from HPOS/WooCommerce | Wrong price/tax/shipping/payment/status | Woo CRUD/cart/checkout/gateway public contracts; no internal namespace/posts assumption/second ledger | HPOS, Blocks/classic, parity, deprecation scans | Not assessed |
| TM-015 | Admin edit capability becomes customer direct-action authority | Customer exceeds My Account action availability | Current Customer Action Matrix from customer-facing public behavior; CRM fallback | Allowed/denied parity and concurrent status tests | Not assessed |
| TM-016 | Case/payment review approval is presented as Woo execution/settlement | False service/payment state | Typed decision, execution, transition, settlement statuses; separate capability/confirmation/result | Approved + failed execution/transition E2E | Not assessed |
| TM-017 | Malicious, polyglot, oversized, decompression-bomb, path-traversal, or metadata-rich upload | RCE/parser abuse/DoS/privacy leak | Signature/MIME/extension/structure checks; quotas; random names; re-encode/strip; scanner/parser isolation; bomb limits | File corpus/fuzz/load tests | Not assessed |
| TM-018 | Protected evidence exposed by predictable/public URL or stale signed link | Payment/CRM privacy breach | Non-public storage; actor/resource-bound short-lived access; reauthorization; no physical path leak | Cross-customer, expiry, referrer/cache tests | Not assessed |
| TM-019 | SSRF/open redirect/arbitrary URL through product, provider, webhook, media or admin config | Internal network access or credential theft | Scheme/host allowlists; DNS/IP recheck; redirect limits; no arbitrary model HTTP tool | SSRF/open-redirect test suite | Not assessed |
| TM-020 | SQL/XSS/command/path injection through messages, labels, model output, import, logs | Code/data compromise | Validate/sanitize, prepared queries, contextual escaping, no shell eval, import schema, CSP where appropriate | Static plus hostile integration/browser tests | Not assessed |
| TM-021 | Provider credential returned in API/admin/log/export or stored plaintext | Secret compromise | Protected secret references, write-only UI, rotation/revocation, redaction, least privilege | Secret scan/API snapshot/log/export tests | Not assessed |
| TM-022 | Customer data sent to Gemini/processor on activation or before merchant/privacy conditions | Unauthorized external disclosure | Provider readiness Unconfigured; activation no network; explicit publish; purpose/data/region/retention/notice/consent gate | Network isolation and transmission-denial tests | Not assessed |
| TM-023 | Full transcript/catalog/order history sent when unnecessary | Excess disclosure/cost and context poisoning | Bounded Context Bundle, source/classification manifest, selection/truncation record, max bytes/items | Bundle minimization and cross-customer tests | Not assessed |
| TM-024 | Summary or durable memory invents, retains, or leaks facts after correction/erasure | Wrong behavior/privacy breach | Source linkage/hash, drift validator/rebuild, supersession, allowlisted consented preferences, derived-data erasure | Drift/correction/expiry/export/erasure tests | Not assessed |
| TM-025 | Hidden reasoning/private scratchpad or unnecessary provider payload stored | Sensitive inference/secret exposure | Structured interpretations/evidence only; prohibited storage/log rules | Schema/repository/log scans | Not assessed |
| TM-026 | Feature Off hides UI but leaves route/tool/job/webhook active | Policy bypass or side effect | Central effective-state service and exposure manifest checked cross-surface | On/Off/Blocked/Degraded cross-surface tests | Not assessed |
| TM-027 | Optional module partial code becomes discoverable or marketed | Unsafe unsupported workflow | Default Off, certification state, no exposure until module evidence passes | Package/route/tool/UI/job inventory tests | Not assessed |
| TM-028 | Queue executes with stale actor, consent, feature, time, stock, order, or policy | Unauthorized/delayed wrong effect | Minimal IDs/versions; revalidation inside job; unique scheduling; bounded retries; cancellation/dead-letter | Stale delayed-job and duplicate/recovery tests | Not assessed |
| TM-029 | Cache key omits actor/market/branch/version or serves stale commerce truth | Cross-customer leak/wrong price/action | Scoped keys, source/config versions, strict TTL/invalidation, no stale sensitive state | Isolation and invalidation tests | Not assessed |
| TM-030 | Migration interruption/destructive rollback corrupts data or storefront | Data loss/fatal outage | Bounded idempotent steps, checkpoints/post-checks, compatibility window, backup/restore, feature block | Partial/repeat/concurrent/rollback/uninstall exercises | Not assessed |
| TM-031 | Operations monitor marks read, consumes confirmation, replays tool, or mutates state on open | Hidden staff side effect | Read-only default; explicit action endpoints/caps; exact View as Customer renderer | Monitor-open no-side-effect assertions | Not assessed |
| TM-032 | Internal notes or staff-only evidence render to customer/export | Confidentiality breach | Separate storage/renderer/classification and capability | Customer view/export negative fixtures | Not assessed |
| TM-033 | Read receipt, presence, stock lock, price lock, delivery, or payment approval is implied without evidence | Deceptive customer state | Reliability/evidence gate; absent by default; exact status vocabulary | Failure/stale UI and historical renderer tests | Not assessed |
| TM-034 | Locale/RTL/date/time ambiguity changes commercial decision | Wrong date, cutoff, amount, address, or confirmation | IANA time zone; absolute date repetition when material; bidi isolation; current calendar/branch data | Arabic/mixed-direction/relative-date/cutoff tests | Not assessed |
| TM-035 | Rate/cost/resource exhaustion through chat, provider loops, search, upload, history, or queue | DoS and uncontrolled provider spend | Per-actor/IP/purpose limits; bounded provider/tool loops; pagination; quotas; circuit breaker; cost budget | Abuse/load/circuit-breaker tests | Not assessed |
| TM-036 | Dependency/update supply-chain compromise or private Woo API breakage | Code compromise/outage | Locked/reviewed dependencies, SBOM/audit, reproducible package, public contracts only, compatibility gates | Dependency/secret/package/deprecation scans | Not assessed |

## STRIDE/LINDDUN coverage

- Spoofing: actor/session, callback/webhook/provider authentication.
- Tampering: messages/state/configuration, confirmations, evidence, migration checkpoints.
- Repudiation: sensitive customer/staff/provider/queue actions with minimal immutable audit.
- Information disclosure: IDOR, files, memory, provider transmission, logs, exports, staff UI.
- Denial of service: provider/tool loops, uploads, queues, queries, migrations.
- Elevation of privilege: role/capability, model/tool, feature, assignment, direct order execution.
- Linkability/identifiability/detectability: guest identifiers, analytics, durable memory, provider requests.
- Unawareness/non-compliance: AI/human disclosure, processor notice/consent, retention/rights, presence claims.

## Acceptance gates

Release requires independent security/privacy review, systematic IDOR and injection tests, protected-file and provider assessments, no unresolved Critical/High issue, zero cross-customer leakage, zero unauthorized/duplicate commerce writes, no confirmation/media bypass, tested incident/reconciliation/rollback, named Security/Privacy/Commerce owners, and attributable evidence. None is currently present; verdict remains **NOT READY**.

