# Actor, capability, and action matrix

This matrix defines minimum gates. It does **not** grant capabilities to WordPress roles. Role names such as administrator or shop manager never replace an explicit capability, assignment, ownership, feature, state, confirmation, or audit check.

## Actor rules

| Actor | Identity source | Permitted baseline | Mandatory denials/gates |
|---|---|---|---|
| Guest shopper | High-entropy first-party guest session resolved server-side | Enabled public product/policy reads, isolated temporary cart, ordinary conversation | No account/order/private resource access; protected checkout requires canonical auth policy unless separately certified guest checkout |
| Authenticated customer | Current WordPress/Woo customer resolved server-side | Owned account, addresses, cart, checkout, orders, cases, reviews, conversations, files, eligible preferences | Exact ownership, current Customer Action Matrix, feature/dependency/consent/rate/state/confirmation gates |
| Support/sales agent | Authenticated user plus explicit Veyra capabilities and assignment | Permitted conversation/case reads, customer-visible messaging, private notes, takeover | View does not grant identity/files/audio/message/decision/execution; no customer commerce authority |
| Payment reviewer | Authenticated user plus review capability and assignment | View eligible protected evidence; request info; decide review | AI extraction advisory; decision does not grant Woo transition |
| Shop manager | Authenticated user plus individually granted capabilities | Only explicitly granted operational actions | Role name is never blanket authority; customer action matrix still controls customer-side actions |
| Administrator | Authenticated user plus explicit capabilities | Configuration/publication/roles/privacy as separately granted | No implicit customer-resource ownership; monitor is read-only by default; sensitive execution remains separate |
| Queue/job/webhook/CLI actor | Verified system entry point carrying minimal IDs/versions | Only the application use case and scope encoded by its registered contract | Reauthorize/revalidate feature, consent, resource/version, time and idempotency; never inherit stale human authority |
| Provider/external service | Authenticated adapter boundary, never a Veyra actor | Return proposed normalized data within route contract | Cannot select actor/resource, grant tools, confirm, or execute commerce |

## Customer action matrix

| Action family | Guest | Authenticated customer | Staff/reviewer | Server-side gates |
|---|---|---|---|---|
| Public catalog/policy discovery | If feature/public visibility permits | Yes | Read only if assigned/authorized admin surface | Feature, market/branch, visibility, rate, approved knowledge freshness |
| Temporary cart read/write | Own guest session | Own cart/session | No staff mutation through customer tools | Exact product/variation/line, Woo authority, idempotency; broad/destructive confirmation |
| Conversational checkout | Login route by default | Own current checkout | No impersonated checkout | `commerce_require_authentication`, feature, Woo eligibility, current totals, final confirmation |
| Owned order read/track | No, unless separately certified verified claim | Owned orders only | Only separately permitted operations view | Ownership, action matrix, status separation, protected fields |
| Direct order action | No | Only current customer-facing action matrix subset | Staff path is separate | Exact preview, full recalculation, financial route, lock, confirmation, execution/reconciliation |
| CRM case | Public support only if explicitly safe; protected case requires auth | Own cases | Assigned cases per capability | Exact scope, submission confirmation, idempotency; notes/decision/execution separation |
| Payment evidence/review | No protected access | Own eligible offline-payment order/review | Assigned reviewer/operator capability | Protected files, explicit submission confirmation, decision/transition separation |
| Handoff | May request if feature enabled | May request | Join/pause/message only with separate caps | Assignment, authorship, audit; no implicit side effect |
| Durable preference memory | Off by default | Only consented allowlisted own preferences | Access only if separately permitted by policy | Published category/purpose/retention, correction/export/erasure, no sensitive inference |

## Canonical WordPress capabilities

| Capability | Candidate actor class (not an automatic role grant) | Minimum authority | Additional mandatory scope |
|---|---|---|---|
| `manage_veyra_settings` | Administrator or explicitly delegated configuration owner | Manage general settings that do not imply customer-content access or sensitive execution. | Published configuration domain and store/site scope |
| `manage_veyra_agent` | Administrator or explicitly delegated configuration owner | Draft, simulate, publish, schedule, and roll back Agent Studio configuration. | Published configuration domain and store/site scope |
| `manage_veyra_context_knowledge` | Administrator or explicitly delegated configuration owner | Manage knowledge, culture, market, branch, location, time, and memory policy. | Published configuration domain and store/site scope |
| `manage_veyra_experience` | Administrator or explicitly delegated configuration owner | Manage and publish Experience Studio configuration. | Published configuration domain and store/site scope |
| `manage_veyra_features` | Administrator or explicitly delegated configuration owner | Manage Commerce Control Center configured states and dependencies. | Published configuration domain and store/site scope |
| `manage_veyra_models` | Administrator or explicitly delegated configuration owner | Configure Google Gemini and approved alternative provider routes, credentials references, fallbacks, budgets, and health tests. | Published configuration domain and store/site scope |
| `view_veyra_dashboard` | Explicitly authorized staff/privacy actor | View privacy-minimized operational summaries. | Privacy-minimized aggregate scope |
| `view_veyra_conversations` | Explicitly authorized staff/privacy actor | View permitted customer-visible conversation history. | Permitted conversation/customer assignment; purpose; field-level data access |
| `view_veyra_customer_identity` | Explicitly authorized staff/privacy actor | View separately protected customer identity/contact data. | Permitted conversation/customer assignment; purpose; field-level data access |
| `view_veyra_attachments` | Explicitly authorized staff/privacy actor | View authorized protected attachments. | Permitted conversation/customer assignment; purpose; field-level data access |
| `play_veyra_audio` | Explicitly authorized staff/privacy actor | Play authorized customer audio and view permitted transcripts. | Permitted conversation/customer assignment; purpose; field-level data access |
| `join_veyra_conversations` | Assigned support/sales actor | Accept or join an authorized human handoff. | Permitted conversation/customer assignment; purpose; field-level data access |
| `pause_veyra_ai` | Assigned support/sales actor | Pause or resume AI for permitted conversations. | Assigned conversation, current handoff state, explicit authorship and audit |
| `send_veyra_support_messages` | Assigned support/sales actor | Send customer-visible staff messages. | Assigned conversation/case, channel policy, explicit staff authorship and audit |
| `add_veyra_internal_notes` | Assigned support/sales actor | Add staff-only notes that can never render to customers. | Assigned conversation/case, staff-only visibility invariant and audit |
| `view_veyra_crm` | Assigned support/manager actor | Read permitted CRM cases and customer-visible history. | Assigned case, related owned resources, current state, separate decision/execution |
| `manage_veyra_assigned_cases` | Assigned support/manager actor | Assign and update permitted cases without executing commerce mutations. | Team/queue assignment scope, related owned resources, current state, separate decision/execution |
| `decide_veyra_cases` | Assigned support/manager actor | Approve or reject eligible service requests according to policy. | Assigned case, related owned resources, current state, separate decision/execution |
| `execute_veyra_case_actions` | Separately authorized commerce operator | Execute a separately authorized, revalidated, and confirmed commerce action resulting from a case. | Assigned case, related owned resources, current state, separate decision/execution |
| `view_veyra_payment_evidence` | Payment reviewer with assignment/scope | View protected payment evidence and unverified extraction. | Assigned review and exact owned order; evidence/decision/transition separation |
| `decide_veyra_payment_reviews` | Payment reviewer with assignment/scope | Request information, approve, reject, supersede, expire, or close eligible reviews. | Assigned review and exact owned order; evidence/decision/transition separation |
| `execute_veyra_payment_transitions` | Separately authorized payment-transition operator | Execute a separately authorized and confirmed WooCommerce transition after review. | Assigned review and exact owned order; evidence/decision/transition separation |
| `view_veyra_analytics` | Explicitly authorized staff/privacy actor | View permitted analytics and evaluations. | Privacy-minimized aggregate scope |
| `view_veyra_audit` | Explicitly authorized staff/privacy actor | View protected audit records. | Protected audit query scope and retention policy |
| `export_veyra_conversations` | Explicitly authorized staff/privacy actor | Export permitted conversation and related personal data. | Permitted conversation/customer assignment; purpose; field-level data access |
| `erase_veyra_data` | Administrator or explicitly delegated privacy/security operator | Perform eligible privacy deletion or anonymization. | Verified subject/request, legal hold, eligible categories, audit |
| `manage_veyra_retention` | Administrator or explicitly delegated privacy/security operator | Manage retention and legal-hold policy. | Verified subject/request, legal hold, eligible categories, audit |
| `manage_veyra_roles` | Administrator or explicitly delegated privacy/security operator | Grant Veyra capabilities to roles or users. | Exact role/user grant diff, self-escalation protection, audit |

## Boundary enforcement

Every UI control, REST permission callback, application service, model tool, job, webhook, CLI command, export, erasure operation, and protected-file endpoint rechecks the same policy. A previous UI render, nonce, prompt, model plan, signed identifier, case approval, review approval, or administrator role is never sufficient authorization.
