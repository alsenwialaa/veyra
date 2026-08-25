# Data, schema, migration, rollback, and uninstall design

Status: **proposed whole-system design with a bounded candidate `0.1.7` implementation on database schema `1.6.0`**. Exact DDL outside implemented migrations, retention intervals, supported source versions, and volume/index evidence remain Open Decisions. This document does not authorize production storage of customer data.

## Storage principles

- WooCommerce remains the system of record for products, prices, stock, carts/calculations, checkout validation, orders, payments, refunds, and statuses. Veyra stores references, journey state, previews, historical displays, evidence, and workflow records; it does not create a parallel commerce ledger.
- High-volume relational state uses dedicated indexed tables, not autoloaded options or unbounded postmeta.
- Each actor-owned row carries store/site scope, opaque public ID, internal primary key, actor/customer or guest-session scope as applicable, created/updated instants, schema version, optimistic resource version, data classification, and retention disposition.
- Client/model IDs never select an unscoped row. Repository methods require an actor/scope object and apply scope predicates in the query.
- Money uses integer minor units plus ISO currency. Times are UTC instants; display-zone snapshots use IANA time-zone IDs. Quantities/units/packs are typed.
- Mutable current state and immutable event/history records are separate. Historical customer-visible payloads never regenerate from current product/order data.
- No hidden chain-of-thought, secret prompt, plaintext provider credential, payment credential, OTP, banking password, or unnecessary raw provider payload is stored.

## Identifier and concurrency proposal

The working design uses an internal unsigned bigint primary key and an opaque, non-sequential public identifier (candidate: ULID/UUIDv7) with a unique index. This is **Proposed**, not approved, under `DEC-015`. Every mutable aggregate also has an integer `resource_version` incremented by compare-and-swap. Public IDs are not authorization.

## Logical schema inventory

Table names below are logical names. Physical names must use the active WordPress table prefix and a stable `veyra_` namespace.

| Logical table | Core purpose | Key scope/indexes | History/retention behavior |
|---|---|---|---|
| `guest_sessions` | High-entropy first-party guest identity, rotation, account-link state | token digest unique; site/status/expiry; linked user | Never store plaintext token; expire/revoke; audit authenticated link |
| `conversations` | Actor-owned conversation aggregate and current foreground references | public ID; site + customer/guest + updated; status | Retention policy required; export/erasure eligible subject to lawful records |
| `messages` | Immutable visible message body, authorship, modality, rendering version, operation/evidence refs | conversation + sequence unique; time; sender/status | Append-only corrections/redactions; historical display retained or redacted by policy |
| `message_references` | Authorized reply quote and product-reference snapshots | message + type; source conversation/message/product | Snapshot immutable; source erasure propagates unavailable/redacted state |
| `conversation_focus` | One foreground focus per conversation/version, including a bounded unresolved-reference set | conversation unique active; exact actor scope; journey/question/version | Schema 1.6 stores `unresolved_references_json`; mutable actor-owned compare-and-swap validates identifier width, uniqueness, row count, and the round-tripped projection |
| `requirement_states` | One actor-owned head for the complete ordered product-requirement history | conversation unique; exact actor tuple/hash; integer version + state hash | Implemented in schema 1.4; exact-source validation, legacy active-to-proposed quarantine, 64-record/49,152-byte history and 24,576-byte active-projection budgets, privacy paths, and authenticated guest re-key support |
| `pending_questions` | Typed visible questions, allowed choices, dependencies, expiry, and current validated binding record | conversation + active/status; journey/step; expiry; binding ID | Schema 1.3 stores one consumed binding with the answered question; answer/invalidation is one-time and versioned, and runtime consumption is also represented as a typed mutation result for truthful failure accounting |
| `answer_bindings` | Proposed future normalized history for multiple/rejected binding attempts | question/message; status; resource IDs/versions | Not implemented as a separate table in 0.1.7; current schema preserves the one validated consumed binding on `pending_questions` |
| `journeys` | Durable typed workflow state/checkpoint | conversation + status; actor; journey type; updated | JSON validated by versioned schema; corrections create version history |
| `journey_events` | Append-only state transitions, invalidations, pause/resume | journey + sequence; event type/time | Audit-grade workflow history without secrets/full unnecessary payloads |
| `context_claims` | Typed claims with source, authority, verification, freshness, sensitivity | actor/conversation/entity; source; effective/freshness | Supersession/contradiction explicit; expired/stale not deleted merely to appear current |
| `context_edges` | Supersedes, contradicts, depends-on relationships | from/to claim; relation unique | Removed/redacted when source erasure requires it |
| `context_bundle_manifests` | Immutable per-turn metadata-only source/selection accounting, versions, classifications, hashes, truncation, and route decision | unique public/bundle hashes; actor + time; conversation + time; turn; retention/hold; route + time | Implemented in schema 1.5 with actor-scoped read-back hash verification, guest re-key, privacy export/erasure, legal hold, retention, uninstall, and migration wiring; default retention, source-deletion propagation, live database behavior, access/volume, backup/restore, and rollback remain unaccepted |
| `conversation_memory_items` | Validated in-conversation requirements, decisions, refusals, open loops | conversation/category/status; source message/state | Mandatory operational continuity for lawful lifetime; corrections supersede |
| `validated_summaries` | Bounded source-linked structured summary and drift state | conversation + version; source range; validity | Rebuild/invalidate when source/state changes; never invent missing facts |
| `durable_preferences` | Optional consented cross-session allowlisted preferences | customer/category/status/expiry; policy version | Module Off by default; inspect/correct/export/erase; no guest cross-link without auth |
| `evidence_ledger` | Material claim -> authoritative/source/assumption links | message/claim/tool result; source; verification | Minimum safe evidence; no hidden reasoning or secrets |
| `tool_executions` | Safe operational trace of authorized tool call/result | correlation/tool/version/result/time; actor type | Redacted arguments/results; privacy-minimized; not proof without authoritative refs |
| `confirmations` | Exact active/consumed/invalidated single-use confirmations | token digest unique; actor/journey/status/expiry; state hash | No plaintext token; retain safe audit metadata per policy |
| `idempotency_records` | Canonical request scope, in-progress/completed/uncertain outcome | actor/action/resource/key digest unique; expiry/status | Same key/different payload conflicts; result/reconciliation reference retained |
| `locks` | Bounded lease/owner/version for aggregates lacking native compare-and-swap | resource/type unique; lease expiry | Stale recovery; not a substitute for post-acquisition version recheck |
| `checkout_journeys` | Persistent actor/cart checkout values, stale dependencies, confirmation/gateway state | actor/session/cart; status/updated/version | Does not duplicate Woo ledger; refresh/revalidate before resume |
| `configuration_versions` | Agent/context/knowledge/experience/feature/provider drafts and publications | domain/status/effective time/version | Published versions immutable; import as draft; rollback selects prior valid version |
| `feature_publications` | Configured/effective state, reasons, health snapshot | feature/site/current; publication version | Current pointer plus immutable published history |
| `knowledge_sources` | Approved source metadata, scope, versions, effective dates, policy | owner/status/scope/effective; source version | Content activation explicit; rollback and erasure propagation required |
| `knowledge_chunks` | Bounded indexed content/provenance | source/version/chunk; scope/language | Reindex/backfill versioned; erase/expire with source; embeddings treated as derived data |
| `crm_cases` | Customer-owned case current semantic state | public ID; customer/order/status/assignment/updated | Decision and commerce execution refs separate |
| `crm_case_events` | Submission, messages, notes, decision, execution, resolution history | case + sequence/type/visibility | Internal notes isolated; immutable customer-visible updates |
| `payment_reviews` | Exact-order evidence-review current semantic state | public ID; customer/order/status/assignment/version | Review decision and Woo transition fields separate |
| `payment_review_events` | Submission/resubmission, requests, decision, transition attempts/results | review + sequence/type/visibility | Original evidence history immutable; safe redaction/retention policy |
| `attachments` | Protected file metadata, ownership, integrity, scan/analysis/retention | actor/conversation/case/review; status/expiry; digest | Random storage key; never expose physical path; secure deletion job |
| `attachment_observations` | Proposed transcript/OCR/image/document values with provenance/confidence | attachment/page/region/type/status | Always proposed until field validation; source erasure cascades |
| `handoffs` | Request, queue, assignment, AI pause/resume, authorship state | conversation/status/assignment/updated | Assignment and customer-visible history; internal data separated |
| `audit_events` | Minimum safe immutable security/commerce/config/privacy audit | actor type/action/target/time/result/correlation; partition/retention candidate | Append-only, capability-protected; legal/retention policy Open |
| `analytics_events` | Privacy-minimized funnel/quality events | event/time/feature/route; pseudonymous scope where lawful | No unnecessary message content; telemetry/consent decision Open |
| `evaluation_runs` | Dataset, route/config/build attribution and scored results | dataset/route/build/date/status | Test data only; no production customer data without explicit approved process |
| `migration_runs` | Schema step, checkpoint, attempts, status, error code, post-check | migration ID/site unique; status/time | Durable resume/diagnostics; safe redacted errors |

Optional-module tables are not created merely because a module exists in the proposal. Add them through the module's certified migration only when the implementation is shipped and exposure remains default Off.

## Record-level invariants

- Every conversation/journey/focus/question/memory/summary/reference is scoped to the same resolved actor and conversation unless an authenticated, audited linking operation explicitly rekeys eligible guest state.
- A requirement head is scoped by conversation ID, actor type, actor ID, and actor-key hash. Its first insert is selected from the still-owned conversation and still-owned customer source message in the same SQL statement; later heads compare the exact prior integer version and state hash.
- Requirement history is canonical and complete, not a mutable active-only list. Stored criteria have unique IDs, one active criterion per typed slot, reciprocal backward-only supersession links, bounded value nodes, bounded encoded history, and a separately bounded active Context projection.
- A schema 1.4 legacy import verifies the exact byte excerpt against an actor-owned customer-visible message. It quarantines old `active` records as `proposed`; provenance validation alone cannot promote historical semantic interpretation to active authority.
- Exactly one active Focus pointer exists per conversation. Paused questions remain stored but cannot become an implicit short-reply target.
- A source deletion or authorization change must invalidate dependent summaries, quotes, context claims, durable bundle manifests, indexes, embeddings, and caches. The manifest propagation implementation and live lifecycle evidence remain release blockers.
- Evidence, case, payment-review, order, payment, fulfillment, and tracking statuses are distinct typed fields or related records.
- Confirmation consumption and idempotency transition occur in one database transaction where the database supports it. External calls are reconciled durably because they cannot share that transaction.
- Woo order IDs are external resource references only. Veyra never assumes posts-table storage or writes Woo rows/meta directly.
- Context Bundle and ProviderRequest HMAC attestations are per-runtime authorization material. They are never durable customer records and must not be copied into message metadata or a future manifest table.
- Current-turn reply, product-reference, attachment, location-presence, and quick-reply inputs are bound to the exact actor-owned persisted message. Transient request values cannot overwrite those persisted modality facts at assembly time.

## Initial schema and migrations

The current candidate declares plugin schema `1.6.0`. `RequirementStateMigration` remains the schema `1.4.0` step and creates one InnoDB `veyra_requirement_states` head per conversation with unique public/conversation identifiers, actor-scope fields, canonical state JSON/hash, integer version, last source message, timestamps, and an actor/version index. Migration fixtures verify the checked-in DDL and postcondition shape. The deterministic repository fixture exercises unique first-head insertion, actor/message-gated `INSERT ... SELECT`, delayed guest insertion after an ownership re-key, stale version/hash denial, and injected first-head/successor SQL failures that must throw instead of becoming ordinary CAS misses. Explicit read-failure injection and live database error behavior remain untested.

Schema 1.4 does not run an unbounded activation backfill. On first read, a non-empty legacy `memory_json.requirements` list is bounded, parsed, rebound to its exact actor-owned visible-message excerpts, and inserted with compare-and-swap. Legacy active records are preserved as `proposed`; records with unavailable/cross-conversation/tampered evidence fail closed. The old memory key is retained for downgrade/data-preservation safety but is excluded from runtime provider context. Privacy export reads the current head when one exists and otherwise projects only the unimported legacy `requirements` key; that projection performs no import, mutation, or authority promotion. Erasure removes both forms through deletion of the requirement head and, in bounded order, the owning conversation row containing legacy memory.

Authenticated guest-to-customer linking re-keys the requirement row inside the existing link transaction, updates the actor hash and timestamp, and advances the integer version while retaining the content hash. That invalidates old `(resource_version, state_hash)` references without pretending the requirement content changed. The atomic first-insert ownership predicate prevents a delayed missing-row guest write from surviving a completed link in the deterministic repository fixture.

Live `dbDelta`, MySQL/MariaDB isolation and duplicate-key semantics, concurrent upgrade volume, authenticated account-link commit/rollback, privacy paging/export/erasure, backup/restore, and rollback evidence remain unrun.

Candidate `0.1.6` added `ContextBundleManifestMigration` as schema `1.5.0`. Candidate `0.1.7` adds `ConversationFocusReferencesMigration` as schema `1.6.0`. The migration adds nullable `unresolved_references_json` to `conversation_focus` only when absent and verifies the postcondition; the clean-install DDL includes the same column. The domain and repository accept at most ten unique, non-empty opaque identifiers, enforce the 36-character journey-ID storage contract, use exact actor-owned reads and compare-and-set writes, encode the list canonically, and reject malformed stored JSON instead of silently dropping continuity state. The focused fixture covers new writes, read-back, foreign actors, zero-row updates, invalid identifiers, duplicate references, and malformed persisted data.

Boot, runtime feature composition, protected-media route composition, and provider transmission require exact stored-schema equality after the bounded migration attempt; an unknown newer stored schema does not run this older runtime. Context Bundle wire schema remains `1.1.0`. The schema 1.5 manifest table persists only metadata: exact included/excluded source identities, versions and classifications, selection decisions, bundle/metadata hashes, actor/conversation/turn scope, route decision, counts, canonical times, retention expiry, legal hold, and lifecycle state. The full selected projection, message text, Tool Results, provider bodies, credentials, and runtime HMAC attestations are excluded.

Save is not accepted on an insert acknowledgement alone. The repository reloads the row through exact actor type/ID/hash predicates and verifies immutable metadata and bundle hashes. Authenticated guest linking re-keys eligible rows in the existing transaction; privacy export/erasure, legal-hold-aware retention, and uninstall are wired. This does not settle `DEC-023`: newly assembled manifests have no default retention deadline until an approved policy exists. Source-deletion propagation, live `dbDelta` and MySQL/MariaDB behavior, indexing/volume, privacy paging, legal-hold operations, backup/restore, and rollback remain release blockers.

1. Activation performs only a bounded preflight and minimum schema/config setup. It does not contact Gemini, inspect the whole catalog, reindex knowledge, or run an unbounded backfill.
2. A schema manifest declares target version and ordered migration IDs. Each migration has preconditions, bounded batch size, checkpoint shape, post-check, rollback classification, and stable diagnostic codes.
3. One migration lease prevents concurrent runners. Each step is idempotent; a repeated completed step is a no-op after checksum/post-check validation.
4. Long data movement runs after activation through an approved queue adapter. Action Scheduler calls occur only after WordPress `init` priority 1 has run or from `action_scheduler_init`. Jobs carry migration ID/checkpoint only and revalidate schema/current plugin version.
5. Partial failure records the last committed checkpoint and safe error. Storefront-critical code supports the previous/current compatible schema window or blocks only the affected Veyra capability.
6. Post-checks verify tables/columns/indexes, expected row counts/checksums where meaningful, actor scope, and read/write repository probes. A migration is not complete merely because DDL returned success.
7. Removal/renaming is expand-migrate-contract across releases: add new shape, dual-read/controlled-write where safe, backfill, validate, switch, then remove only after rollback window closes.

## Upgrade and rollback classes

| Class | Example | Rollback rule |
|---|---|---|
| Reversible metadata/config | Add nullable field/index, publish new config | Repoint immutable publication or apply tested down migration |
| Forward-only compatible | Normalize data into new relation while old readers still work | Roll code back within compatibility window; retain new data |
| Destructive/externally coupled | Drop/transform source, send gateway/order effect | Do not automate down migration; require verified backup/restore plan and reconciliation |

Code rollback must first switch features/provider routes to Off or a previously certified version, stop new writes, drain/cancel affected jobs, and reconcile in-flight external effects. Schema rollback is never a blind inverse.

## Deactivation

Deactivation stops new Veyra execution, cancels or pauses plugin-owned schedules where safe, invalidates pending confirmations, changes in-progress idempotency records to `uncertain` before releasing locks, and preserves data/configuration for reactivation. The candidate performs those state changes transactionally and rolls back on failure. It does not delete WooCommerce data, customer records, conversations, or evidence. Live WordPress/database interruption and reactivation evidence remain required.

## Uninstall

Uninstall behavior is retention-policy controlled and requires an explicit merchant deletion decision. It must:

- deny deletion when policy/legal hold says retain;
- cancel Veyra schedules and webhooks;
- revoke generated access links/tokens and delete temporary/cache files;
- erase or anonymize eligible Veyra tables and protected attachments in bounded resumable work;
- propagate source deletion to summaries, quotes, indexes, embeddings, caches, and bundle references;
- remove capabilities/options only after data disposition is recorded;
- preserve WooCommerce products, customers, orders, payments, refunds, and unrelated WordPress/extension data;
- leave an attributable completion/failure report without secrets.

Because WordPress uninstall execution may be time-limited, large deletion requires an explicitly designed pre-uninstall purge/export workflow; the plugin must not claim all data was removed when a bounded uninstall could not complete.

## Required proof before approval

- Exact DDL and indexes reviewed against representative store tiers.
- Clean install and repeated migration.
- Upgrade from every supported schema; interruption/resume and concurrent-run denial.
- Query plans and p95 budgets for actor-scoped timeline, focus, journey, cases, reviews, and audit.
- Cross-customer repository tests and cache-key tests.
- Export/erasure propagation, legal hold, protected-file secure deletion.
- Rollback compatibility window and restore exercise.
- Deactivation/reactivation and uninstall preserving WooCommerce/unrelated data.
