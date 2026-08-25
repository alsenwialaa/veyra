# Architecture overview and domain map

Status: **documented design direction with bounded 0.1.7 implementation evidence; no whole-release readiness or acceptance determination is recorded here**. Canonical proposal: v4.1, sections 0–34.

## System shape

Veyra is one deployable WordPress plugin organized as a modular monolith. Domains expose typed application ports; WordPress, WooCommerce, provider, database, file, queue, REST, CLI, webhook, admin, and frontend concerns are adapters. The architecture does not permit a central `ChatService` to absorb authorization, storage, commerce, and prose generation.

```text
Customer/admin adapters
  -> application use cases
    -> domain policies and typed state
      -> ports
        -> WordPress/WooCommerce/provider/storage/queue/file adapters
```

Dependency direction is inward. Domain code never receives raw HTTP requests, WordPress globals, React state, provider SDK objects, SQL rows, or arbitrary model JSON. Infrastructure may depend on public platform APIs and maps them into provider-independent and WooCommerce-authoritative contracts.

## End-to-end authority path

Every material turn follows the same bounded path:

1. Resolve the server-side actor or secure guest session; validate request, nonce/CSRF where applicable, rate, payload, attachments, and feature state.
2. Normalize text and enabled modalities while retaining provenance and uncertainty.
3. Resolve store, market, branch, locale, location, authoritative time, account, page, cart, checkout, consent, and dependency health.
4. Load one foreground Conversation Focus, its Pending Question, the foreground Journey State, paused journey identifiers, bounded recent visible messages, validated Conversation Memory/summary, the exact actor-owned requirement head, and permitted preferences.
5. Reload the persisted customer turn and assemble a minimized, actor-scoped, versioned, runtime-attested Context Bundle.
6. Seal one closed provider-independent phase request; the adapter may transmit only the exact gate-approved finalized provider body.
7. Deterministically reconcile source authority, current shopper preference precedence, references, answer schema, ownership, freshness, and risk.
8. Validate a strict ordered Plan against the effective feature/tool registry, actor, capability, autonomy, consent, market, dependency, rate, and policy gates.
9. Execute the minimum authoritative reads. For writes, resolve exact resources and apply confirmation/idempotency/lock rules.
10. Observe current authoritative results; repair within hard loop/tool/time/cost bounds.
11. Verify claims, hard requirements, side effects, current/stale state, disclosures, language, location, time, and prohibited claims.
12. Compose the customer-visible response and components from verified data only.
13. Persist visible history, validated state, evidence, audit, analytics, and safe operational traces; never persist hidden chain-of-thought.

Any failure ends in a stable Blocked, Degraded, failed, stale, partial, or uncertain result. It never activates a hidden regex/keyword/fixed-wizard substitute.

## Domain map

| Domain | Owns | Depends on / authority boundary |
|---|---|---|
| Bootstrap & Lifecycle | dependency-safe boot, activation, schema version, migrations, health, deactivation, uninstall | WordPress public hooks; no remote AI call or heavy activation work |
| Identity & Policy | actor resolution, guest sessions, capabilities, ownership, consent, rate/abuse | WordPress users/capabilities; server-resolved scope only |
| Configuration & Publication | drafts, validation, immutable published versions, schedules, rollback | capability-protected publication; configuration cannot expand authority |
| Features | configured/effective state, dependency graph, safe fallbacks, exposure removal | checked at UI, REST, service, tool, job, webhook, CLI, analytics |
| Conversation | conversations, visible messages, references, authorship, delivery state | actor-scoped storage; shared historical renderer |
| Journey | durable typed workflows, checkpoints, pause/resume, targeted invalidation | one foreground journey; paused journeys remain separate |
| Context | Context Graph, claims, authority, evidence, culture, location, time | WooCommerce/approved records and published sources outrank interpretations |
| Memory | Focus, Pending Questions, bounded Conversation Memory, summaries, optional preferences | source-linked, versioned, correctable, isolated, retention governed |
| AI Orchestration | interpretation, planning, provider-independent response, bounded repair | model proposes only; application policy authorizes |
| Provider | route manifest, credentials references, Gemini adapter, readiness, fallback, metering | exact model ID centralized; readiness initially Unconfigured |
| Tools | universal contract, registry, exposure policy, result/error mapping | logical names never grant authority |
| Knowledge | approved sources, versioning, retrieval, citations, freshness, conflicts | untrusted source content cannot alter policy/tools |
| Catalog | current products, variations, offer, stock, purchasability, references | WooCommerce/public adapters authoritative |
| Requirements & Recommendation | hard/soft requirements, candidates, filters, ranking, trade-offs | hard filters before ranking; no first-result resolution |
| Cart | exact line resolution, mutation plans, coupons, totals, invalidation | WooCommerce cart and calculation APIs authoritative |
| Checkout | persistent journey, fulfillment, contacts, shipping, billing, payment, final review | WooCommerce fields, rates, totals, gateways, validation authoritative |
| Orders | owned-order read, status separation, Customer Action Matrix, amendments | Woo CRUD/public customer actions; no storage assumptions |
| CRM & Handoff | staff-review cases, messages, notes, decisions, execution linkage, takeover | submission/decision/execution/resolution stay separate |
| Payment Review | protected evidence, proposed extraction, reviewer decision, transition | AI advisory only; Woo transition/settlement separate |
| Media | upload admission, protected files, transcript/OCR/image/document observations | content is untrusted proposed evidence; media never confirms |
| Confirmation | previews, state hash, one-time record, invalidation, consumption | exact actor/action/resources/versions/summary binding |
| Idempotency & Concurrency | canonical request keys, locks, callback dedupe, reconciliation | every retriable write defines one execution boundary |
| Experience & Rendering | mobile messaging, cards, snapshots, accessibility, RTL | presentation cannot hide commercial truth or authorize writes |
| Operations & Audit | health, monitor, takeover, queues, privacy operations, audit | least privilege; monitor read-only by default |
| Analytics & Evaluation | privacy-minimized events, scenario packs, release thresholds | cannot replace authoritative state or acceptance evidence |

## Authority and state invariants

Two precedence ladders remain separate:

- Factual/commerce truth: current WooCommerce or approved system → current published merchant policy → verified account/resource → explicit shopper fact → historical snapshot → model interpretation → hypothesis.
- Shopper intent/preferences: explicit current correction/refusal → current message/explicit reference → valid answer to foreground Pending Question → foreground Journey decisions → validated Conversation Memory/summary → fresh consented durable preference → merchant default → hypothesis.

Typed states must not collapse:

- statement / proposed / validated / authoritative / stale;
- information / choice / preview / confirmation / execution / verified completion;
- order / payment / fulfillment / shipment/tracking / CRM / payment review;
- case or review decision / commerce execution;
- historical display / refreshed current state;
- short reply / proposed binding / validated target / resulting update;
- Conversation Memory / optional Durable Preference Memory / hidden reasoning.

## Implemented 0.1.4 requirement-state through 0.1.7 continuity/provider boundaries

Current-conversation product requirements now have one dedicated actor-owned head. The complete ordered history is canonically hashed, every successor increments an integer resource version, and successor persistence compares the exact actor tuple, expected version, and expected hash. First-head creation is an atomic `INSERT ... SELECT` that requires the conversation and the last source customer message to still belong to the same actor in the statement that creates the unique conversation head. This closes the delayed guest-write window at that boundary; it is not a substitute for live MySQL/MariaDB concurrency evidence.

The aggregate applies three independent bounds before persistence or provider use: at most 64 records, at most 256 nodes in each criterion value, at most 49,152 canonical encoded bytes for complete history, and at most 24,576 canonical encoded bytes for the active projection. Stored graphs also reject competing active slots and inconsistent supersession links.

The schema 1.4 compatibility path treats legacy `conversation_memory.requirements` as untrusted input. It resolves every cited source and status-source message inside the exact actor-owned conversation, recomputes the byte-offset/length excerpt digest, rejects cross-conversation or tampered provenance, and changes every legacy `active` record to `proposed`. Exact source validation does not prove that the old field/operator/value was semantically entailed, so quarantined records do not enter recommendation filtering or ranking. Import preserves the legacy memory blob rather than racing a whole-blob rewrite.

The runtime Context Bundle contains the exact requirement `resource_version`, `state_hash`, and active projection, and excludes the retained legacy requirements key so two sources cannot compete. Requirement-dependent recommendation handlers reject caller-supplied requirements and scores, compare the expected head before computation, and recheck it after computation before returning any advisory result. Diversification consumes the server-ranked candidate objects from one WooCommerce candidate snapshot within that call; it does not re-read WooCommerce between ranking and diversity selection.

Candidate `0.1.7` publishes and enforces Context Bundle wire schema `1.1.0`. `CommerceAgent` first persists safe turn metadata; the assembler reloads that exact actor-owned message and reconstructs reply, product-reference, attachment-presence, location-presence, and quick-reply modalities from persistence. The transient request cannot replace persisted modalities. Reply text and exact versioned product-reference bindings are reauthorized through visible history, while historical product bodies, attachments, location values, raw evidence/render/correlation blobs, unvalidated memory/summaries, durable preferences, catalog history, and order history are omitted.

The provider actor identifier is a one-bundle pseudonym. Site scope derives from the current WordPress blog identity, so different blog IDs do not share a provider site pseudonym. Network-wide activation remains denied and multisite remains uncertified. The projection carries closed focus/foreground-journey structures, paused journey IDs, recent messages, the exact requirement head, runtime context, one bounded Woo cart snapshot, included-source metadata, a 13-section selection manifest, route/privacy metadata, whole-bundle limits, and expiry. Focus must agree with the only active journey. Modalities, selected data, references, sources, and selection reasons must align exactly.

`ContextBundle` can be issued only with `ContextBundleAttestor`; the per-runtime HMAC covers the canonical projection and raw server actor tuple. `ProviderRequestAttestor` separately seals the complete provider-independent request: route, instruction, input, tools, response schema, timeout, metadata, traffic/purpose/phase, and bundle hash. Decision, response, semantic-verification, and readiness phases each have closed exact envelopes. A valid-looking object issued or widened outside the shared runtime boundary is denied.

After adapter mapping, the transmission gate reconstructs the only valid finalized Gemini body and requires canonical equality plus the route request-byte bound before credential access. It also reruns current release/readiness policy, the Context Bundle contract, exact database schema equality, expiry, embedded-bundle equality, and metadata binding. Readiness has a separate closed response schema and context-free nonce/tool probe; the real service-to-adapter path requires the closed structured result plus exactly one native probe call. Freshness and every declared required capability are mandatory, while a successful capability probe still stores `release_certified=false`. This candidate accepts only the one default route ID; every alternate/fallback route is denied until it has an independent state and exact architecture rather than inheriting the default route's certification.

Pending Question consumption is now represented as an authoritative typed mutation result. If a later provider phase fails, failure persistence reports a partial completed state change and safe changed-resource evidence rather than pretending nothing changed. The pre-decision Context Bundle is still reused, however, so a coherent transactional snapshot and general post-mutation authority refresh/rebinding remain missing.

`WooAuthoritativeContextProvider`, cart tools, and checkout paths require the exact logged-in customer: Veyra user ID, current WordPress user ID, Woo customer ID, and Woo session customer ID must agree. Guest and mismatched actors receive unavailable/blocked state until a complete guest-to-Woo-session binding exists. Product-reference commands carry a deterministic reference ID, source message, and exact product/variation tuple; the server rebuilds the public references from the actor-owned source message and accepts one exact match only.

The full provider projection and runtime attestations remain transient. Database schema `1.5.0` added an immutable metadata-only Context Bundle manifest with identity-level included/excluded source decisions, classifications, selection reasons, hashes, route/time fields, retention state, and legal hold. Schema `1.6.0` adds durable bounded Conversation Focus unresolved references and exact actor-owned compare-and-set/read-back validation. Migration, guest re-key, privacy export/erasure, retention, legal hold, and uninstall are wired, but the default Context Bundle retention period, source-deletion propagation, live database behavior, access/volume evidence, and rollback/restore remain blockers.

Provider-bound text and requirement values are sanitized before contract validation, canonical hashing, manifest creation, and attestation. Response and semantic-verification phases receive only exact-version, recursively closed ToolResult projections; missing, open, dynamic-map, or unregistered profiles fail closed. Catalog, knowledge, and recommendation profiles now use closed list-shaped projections, and the provider continuation value object can be created only from a validated projection. Coverage, live behavior, and independent acceptance remain incomplete. The checked-in route therefore keeps `context_manifest_persistence_certified`, `prohibited_data_filter_certified`, `provider_result_projection_certified`, `woocommerce_actor_binding_certified`, and `context_snapshot_consistency_certified` false, in addition to its unconfigured transmission/privacy/evaluation/release state.

This remains bounded source and deterministic-fixture evidence, not a completed provider or commerce release unit. A checked-in Draft 2020-12 runner compiles the registered schemas and resolves their references; extension invariants remain mapped to runtime tests. Deterministic whole-workflow product grounding, production requirement semantic promotion, live WordPress/WooCommerce/MySQL/HPOS/Action Scheduler/provider evidence, PHPUnit execution, and formal acceptance remain incomplete. Catalog governance continues to block the seven `implemented_not_tested` handlers.

## Required cross-domain controls

- Every entry point must resolve actor and ownership independently. Client/model resource IDs are hints only.
- Every REST route must have an explicit permission callback. Nonces are CSRF controls, not authorization.
- Every tool must have typed input/output, stable codes, feature/actor/capability/dependency gates, server-side resource resolution, current-state validation, audit/privacy behavior, and deterministic tests.
- Sensitive writes require a fresh exact preview and atomic confirmation/idempotency consumption, followed by reauthorization, revalidation, version/lock control, authoritative execution, reconciliation, and verified result.
- WooCommerce public CRUD, cart, checkout, Store API where appropriate, customer-facing actions, and gateway contracts are the only commerce authority. No posts/postmeta assumption or internal WooCommerce namespace is allowed.
- Queue jobs must carry minimum identifiers and versions, revalidate actor/resource/consent/time/feature state, and never assume exactly-once execution.
- Attachments must use protected actor-bound access, content/structure limits, randomized names, scanning hooks, metadata stripping, quotas, retention, and secure deletion.
- Historical cards, quotes, and structured messages must store immutable display payloads and a rendering schema version. Current values are fetched separately.
- Optional modules remain default Off and absent from active exposure until separately certified.

## Provider boundary

Google Gemini is the default provider family. The official-source review was refreshed on 2026-08-25 for the current `steps`-based Interactions contract, `store=false`, the candidate `v1beta/interactions` endpoint, and stable candidate ID `gemini-3.7-flash`. These are Proposed/uncertified observations, not release selection. The design manifest retains a null release-selected model ID. The executable candidate route is deliberately `Unconfigured`, with shopper transmission, privacy publication, evaluation, release certification, and the five independent Context/privacy/result-projection/Woo/snapshot certifications false. Activation transmits nothing, and an explicit readiness test cannot self-promote those flags.

## Runtime compatibility guardrails

Proposed implementation minima are WordPress 6.5, PHP 8.1, and WooCommerce 8.5 with no arbitrary upper bound. These are engineering gates, not tested-compatibility claims. The compatibility matrix remains `Not assessed`. Boot, feature exposure, protected-media composition, and provider transmission require the stored schema to equal `VEYRA_SCHEMA_VERSION`; unknown newer schemas do not run older code. WooCommerce order access is through CRUD for HPOS safety. Action Scheduler APIs are called only after WordPress `init` priority 1 has run or from `action_scheduler_init`, and jobs remain application-idempotent. No live HPOS or Action Scheduler acceptance evidence exists.

## Unmet architectural evidence gates

No readiness or acceptance result is assigned by this document. A future attributable candidate assessment must still provide connected implementation, live migration evidence, deterministic and integration tests, browser/E2E, security/privacy, WCAG/RTL, compatibility, performance, provider evaluation, qualified human review, operational exercises, all anchor/DoD evidence, and zero release blockers.
