# Privacy, retention, export, erasure, and protected-file policy

Status: architecture policy with bounded schema `1.6.0` requirement-state, Conversation Focus, and metadata-only Context Bundle manifest privacy adapters. Jurisdiction, legal basis, processor terms/regions, retention intervals, legal holds, notices, consent wording, staff-access policy, telemetry, and deletion authority remain Open Decisions. No optional durable memory, external customer-data transmission, or production privacy acceptance is authorized by this document.

## Privacy principles

- Purpose limitation: each read, storage, provider transmission, staff view, audit event, export, and erasure operation has a published purpose and minimum data set.
- Fail closed: provider readiness is Unconfigured; activation transmits no customer data. A saved credential alone does not publish a route.
- Actor scope: customer/guest records, memory, files, quotes, cases, reviews, and caches are server-owned and ownership checked at query and delivery.
- Data/state separation: current-conversation continuity is mandatory for its lawful operational lifetime; optional Durable Preference Memory is separate, category-allowlisted, consent/policy governed, and Off by default.
- No prohibited collection: full card data, CVV, bank password, OTP, hidden reasoning, inferred protected traits, biometric identity, invasive fingerprinting, unnecessary precise location, and unnecessary raw provider payloads are not collected or retained.
- Customer transparency: AI/human authorship, authorized staff review, external processors where required, material assumptions, and rights are disclosed truthfully.

## Data inventory and disposition

| Category | Examples | Default behavior before policy approval | Export/erasure design |
|---|---|---|---|
| Public commerce | Published product/policy content | Read current authoritative source; avoid duplicate persistence | Public/current references; no customer erasure requirement unless embedded personal data |
| Identity/contact/address | Account IDs, recipient, phone, email, address | Read only for exact authorized workflow; do not send externally without purpose gate | Include eligible customer data; erase/anonymize Veyra copies while preserving lawful Woo records |
| Conversation/history | Messages, structured cards, quotes, references | Retain only under approved operational policy; historical snapshots immutable | Export shared rendering; erasure/redaction propagates to quote/source availability |
| Focus/journeys/memory | Questions, unresolved references, refusals, checkpoints, summaries, and open loops | Mandatory only for current/eligible resumable conversation lifetime; schema 1.6 durably stores the bounded Conversation Focus unresolved-reference set | Export eligible state; erase/invalidate summaries, graph claims, bundles, caches |
| Requirement state | Complete current-conversation requirement/correction history with exact visible-message provenance, actor scope, version, and hash | Dedicated schema 1.4 head; 64 records, 256 value nodes per criterion, 49,152 encoded history bytes, and 24,576 active-projection bytes; legacy active values are quarantined as proposed | WordPress export includes the current actor-owned head or a projection of only the unimported legacy requirements key; erasure deletes both forms through the head/conversation paths; guest-to-customer linking re-keys the head and advances its version |
| Context Bundle manifest | Metadata-only included/excluded source identities, classifications, selection decisions, bundle/metadata hashes, actor/conversation/turn, route decision, counts, times, retention and legal hold | Schema 1.5 actor-scoped immutable row; full selected text, provider body, Tool Results, credentials, and runtime attestations excluded; retention expiry is null until `DEC-023` is approved | Actor-scoped WordPress export/erasure, authenticated guest re-key, legal-hold-aware retention, and uninstall are wired; source-deletion propagation and live lifecycle evidence remain required |
| Durable preferences | Language/branch/units/voluntary product preferences | Module Off; no write until policy/category/consent passes | Inspect/correct/reset/export/erase eligible item; supersession retained only as law permits |
| Cart/checkout references | Session IDs, selected contact/rates/payment, previews | Veyra state only; Woo remains authoritative; short freshness | Export eligible journey; erase expired local state subject to dispute/legal needs |
| Orders/payments/refunds | IDs, historical snapshots, status references | Do not duplicate ledger; retain lawful references/evidence only | Woo lawful records may remain; explain retained categories |
| CRM/payment review | Requests, decisions, customer updates, evidence links | Requires authenticated ownership and published operations policy | Export customer-visible/owned content; internal notes protected; lawful service/audit may remain |
| Attachments/observations | Voice, image, receipt, document, OCR/transcript | Reject/hold unless exact purpose, limits, storage and retention policy exists | Export eligible original/derived data securely; delete original, derivatives, thumbnails, indexes, caches |
| Provider traces | Route/version, usage, safe error, evidence refs | Store minimized metadata; avoid raw request/response | Include personal trace only where applicable; delete/anonymize derived metadata per policy |
| Audit/security | Actor type/action/target/result/time/correlation | Minimum safe metadata only; separate protected access | Lawful retention may override erasure; explain; never include secrets/full unnecessary content |
| Analytics/evaluation | Privacy-minimized events, test scenario results | Telemetry decision Open; no production content by default | Aggregate/anonymize; subject access/erasure where identifiable and lawful |

## External-service transmission gate

Before sending any customer/store data to Gemini, speech, vision, maps, storage, notification, analytics, or another processor, the published service record must contain:

1. service/legal identity and approved adapter/route version;
2. exact purpose and capability;
3. data categories and field-level minimum payload;
4. endpoint/region and transfer basis where known/required;
5. provider request-storage/training/retention settings;
6. security/processors/subprocessors assessment;
7. customer notice, consent, or explicit initiating action where required;
8. merchant approver, publication version, effective/expiry/review dates;
9. safe manual/local fallback or Blocked behavior;
10. disable/revoke/deprecation response and deletion obligations.

The transient Context Bundle records selected data plus selection/exclusion metadata, byte/item limits, purpose, route, and transmission decision. Schema 1.5 separately persists a metadata-only actor-scoped manifest with every included/excluded source identity/version/classification and read-back verified hashes. Unrelated transcript/catalog/order history and full provider payloads are excluded. Candidate `0.1.7` also sanitizes provider-bound text before contract/hash/attestation and requires registered recursively closed ToolResult projections; those controls remain uncertified.

## Retention policy engine

Retention is per data class/purpose/jurisdiction/status, not one global number. Each policy needs `policy_id`, version, owner/approver, legal basis or operational purpose, start event, interval or event-driven trigger, legal-hold rule, anonymization/deletion action, derived-data propagation, external-processor deletion, audit proof, and review date.

Until intervals are approved:

- optional Durable Preference Memory, alerts, sharing, and other optional persistent modules remain Off;
- attachment-dependent features remain Blocked unless `VEYRA_PROTECTED_STORAGE_PATH` names an explicitly configured absolute non-public path, an approved malware-scanner callback is installed, and `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS` is explicitly set to a whole number from 3,600 through 31,536,000 seconds;
- mandatory active conversation state is retained only as needed for the live/eligible resume workflow and must not be represented as a final legal policy;
- new Context Bundle manifests receive no invented retention deadline; only an explicitly expired non-held row is eligible for automated cleanup;
- no automated permanent deletion interval is invented.

Legal hold is separately authorized, purpose-limited, scoped, audited, reviewable, and never a blanket excuse to retain unrelated data.

## Export

An export request requires authenticated subject resolution, capability/authorization for staff execution, rate/abuse controls, legal-scope evaluation, and audit. The package uses versioned schemas and includes, as eligible: visible conversations/history, references and historical snapshots, focus/questions/journeys/memory/summaries, the schema 1.4 actor-owned requirement head, schema 1.5 metadata-only Context Bundle manifests, durable preferences, cases/payment reviews and customer-visible updates, attachments/derived observations, consent and relevant operation metadata. Internal notes, other customers, secrets, protected security detail, hidden reasoning, and staff-only evidence are excluded.

The bounded compatibility adapter has two mutually exclusive requirement paths per conversation:

- If a schema 1.4 requirement head exists for the exact actor tuple/hash, the allowlisted current-state projection exports that head.
- If no matching head exists, the legacy adapter actor-scopes the conversation and projects only `memory_json.requirements`, labels it `legacy_conversation_memory_not_yet_imported`, and excludes every other memory key. This is a subject-data projection only: it does not validate semantic authority, import a head, change status, or mutate the conversation.

The current implementation leaves the legacy requirements key in storage after a successful lazy import to avoid a blind whole-memory rewrite. Runtime provider context removes that duplicate, and privacy export suppresses the legacy projection once a matching current head exists. A future cleanup may remove the redundant key only through a separately tested compare-and-swap migration that preserves unrelated memory.

The checked-in WordPress adapter is a bounded, paged privacy callback; its live paging, authorization, completeness, malformed-row, and failure behavior still needs WordPress integration evidence. If a future standalone downloadable package is added, package generation must run through an approved asynchronous queue, use required encryption/protection and short-lived actor-bound delivery, expire explicitly, and delete its temporary artifact afterward. A UI “download” is not proof that a complete export was generated.

## Erasure/correction

1. Verify subject and exact requested scope; resolve legal hold/lawful Woo/service/audit retention.
2. Produce a preview of erasable, anonymizable, retained, and externally propagated categories.
3. Treat destructive broad erasure as sensitive: exact confirmation, idempotency, lock, audit.
4. Delete/redact/anonymize primary Veyra records in bounded batches.
5. Propagate to summaries, Context Graph claims/edges, Context Bundle manifests, quotes/references, embeddings/indexes, thumbnails/transcripts/OCR, caches, queued payloads, exports, shares, alerts, provider-managed copies where contracted.
6. Preserve immutable evidence that an operation occurred without retaining erased content.
7. Reconcile and report exact completion/partial/failure; never claim complete deletion while a processor/job failed.

Correction creates a superseding value and invalidates dependent decisions/previews/confirmations. It does not rewrite what the customer historically saw; a redaction policy may replace content with an explicit unavailable/redacted marker.

For schema 1.5, the eraser includes `requirement_states` and eligible non-held `context_bundle_manifests` in actor-scoped bounded deletion and later deletes the actor-owned conversation containing any unimported or retained legacy requirements key. Legal-held manifests are retained and counted as retained rather than falsely reported as erasable remainder. Authenticated guest linking is not erasure: it re-keys eligible requirement and manifest rows within the account-link transaction and must roll back with the rest of the link if any required write or audit event fails.

## Protected-file pipeline

Upload acceptance, storage, analysis, and customer/staff viewing are separate authorizations:

1. Resolve actor, feature, exact purpose/resource, quota, and consent/notice.
2. Stream with hard byte/time limits; reject partial/oversized input.
3. Validate extension, declared MIME, magic signature, structure, dimensions/pixels/duration/pages, archive/decompression ratio, and supported parser profile.
4. Generate a cryptographically random storage key; never trust user filename/path.
5. Quarantine; re-encode supported images/audio where safe; strip unnecessary EXIF/location/metadata.
6. Invoke approved malware scanner and isolated parser hooks; failure is Blocked, not “clean”.
7. Store outside public predictable paths or behind an authenticated delivery controller; encrypt at rest where the approved design requires it.
8. Issue only short-lived, purpose-specific, actor/resource-bound access; apply no-store/private caching and safe content-disposition; never disclose physical path.
9. Analyze content as untrusted proposed evidence with page/region/time provenance and confidence. No file/OCR/transcript/QR authorizes a sensitive action.
10. Apply per-purpose retention, secure deletion, derivative/index/cache propagation, and audit.

The candidate invents no protected-media retention default. Omitting either deployment constant, configuring a public/invalid storage path, omitting the scanner callback, or supplying a retention value outside the bounded range keeps media routes Blocked. Authorized delivery rechecks actor/conversation ownership and attachment usability, then verifies the exact persisted byte count and SHA-256 while copying at most 10 MiB into process memory; a mismatch returns a stable integrity failure and never streams unverified bytes or spills plaintext into a generic temporary directory. These controls are bounded source/fixture evidence, not deployment or privacy certification.

Staff needs separate `view_veyra_attachments`, `play_veyra_audio`, or `view_veyra_payment_evidence` capability and assignment/purpose. Opening the monitor does not grant file access.

## Logging and audit minimization

Allowed safe metadata: actor type/opaque scoped ID where needed, action/tool/version, target type/opaque ID, authoritative time, result/reason, correlation, feature/provider route version, confirmation/idempotency outcome, safe size/latency metrics. Prohibited: API keys, auth cookies, tokens, full card/bank/OTP data, hidden reasoning, secret prompts/security instructions, physical file paths, or unnecessary message/evidence bodies.

## Required evidence

Legal/privacy review; processor and region decisions; customer/staff notices; retention/hold table; provider no-activation-call and transmission-denial tests; redactor false-positive/negative and data-minimization tests; complete closed ToolResult projection tests; cross-customer file/record tests; malicious upload corpus; export completeness/security; erasure/derived-data propagation; processor deletion failure; audit access/redaction; backup/restore and secure deletion behavior. Checked-in privacy/manifest/media runners are deterministic fixtures only. They cover privacy callback failure propagation, unresolved-reference export inventory, explicit protected-media retention, and byte/checksum denial, but do not establish the live gates. WordPress privacy paging, manifest/current-head/legacy export completeness, bounded repeated erasure, legal-hold operations, source-deletion propagation, concurrent writes, retained-data notices, authenticated account-link commit/rollback, malicious-file deployment behavior, and independent privacy/legal approval remain release blockers and are currently Not assessed.
