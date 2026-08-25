# Confirmation, idempotency, locking, callbacks, and recovery

This design applies to every sensitive action and every retriable write. It is domain infrastructure, not a button behavior or prompt convention.

## Action classification

Proposal-sensitive actions include order placement; order amendment/cancellation; cart clear; CRM submission; payment-evidence submission/resubmission; return/exchange/refund request; additional payment; subscription creation/material change; loyalty redemption; consented alerts; sensitive durable memory; and customer-data deletion. Adapter or merchant policy may classify additional actions as sensitive, never fewer.

## Confirmation aggregate

One record contains:

- opaque confirmation ID and non-reusable token digest;
- resolved site, actor/session, customer, conversation, and foreground journey;
- exact logical action/tool/version;
- server-resolved resource IDs and current versions;
- canonical material payload and acknowledgements;
- canonical state hash algorithm/version and digest;
- visible complete-summary message ID/rendering version/language;
- creation, expiry, status (`active`, `consumed`, `invalidated`, `expired`), and reason;
- idempotency scope/key digest and correlation ID;
- safe audit reference.

Plaintext reusable confirmation tokens are never stored. The visible summary must include every material product/variation/quantity/amount/contact/fulfillment/shipping/tax/fee/payment/term/effect required by the action.

## Lifecycle

1. Resolve actor and exact owned/authorized resources server-side.
2. Read current authoritative state and build a non-mutating preview.
3. Reject ambiguous, missing, invalid, stale, recalculating, or unsupported scope.
4. Canonicalize typed material state with deterministic ordering, normalized money/currency/quantity/time, resource versions, acknowledgements, and policy/config versions.
5. Hash the canonical form and create one active expiring confirmation tied to the foreground journey.
6. Render a complete customer-visible summary and one unambiguous question; link its message/version to the record.
7. On response, let AI propose semantic binding, then deterministically require the same actor/conversation/journey/question, exactly one active record, valid schema, no expiry/invalidation, and explicit link to the summary prompt.
8. Acquire the idempotency/aggregate lock or compare-and-swap version.
9. Reauthorize actor/capability/ownership/feature/consent/rate and re-read every current material dependency.
10. Re-canonicalize and require the same hash. Any mismatch invalidates without execution.
11. In one local transaction where supported, create/claim idempotency state and consume confirmation using expected versions.
12. Execute through WooCommerce or the approved adapter.
13. Persist local result state and correlation, then query/observe authority as required.
14. Reconcile all affected cart/order/payment/refund/stock/shipping/fulfillment/case/review state.
15. Build customer success/partial/failure/uncertain output only from verified result data and audit the minimum safe facts.

A quote, product reference, attachment, previous approval, silence, background audio, OCR, image/QR, ordinary click, or old “yes” is not confirmation.

## Invalidation graph

Any material change invalidates confirmation, including product, variation, quantity, unit/pack, current price, stock, coupon, cart version, contact/address, branch, fulfillment, package/rate, shipping cost/estimate, tax, fee, currency, payment method/eligibility, gateway conditions, required field, consent/acknowledgement, order/action-matrix/version, case/review state, policy/configuration, actor/session/ownership, or relevant time window.

Invalidation is targeted but transitive. For example, cart change invalidates checkout calculation, rate/payment selection, totals, preview, and confirmation; address change invalidates shipping/tax/fees/payment/totals/confirmation; order change invalidates action matrix and amendment preview.

## Idempotency contract

Every write declares:

- key source and entropy;
- actor + action + exact resource scope;
- canonical request payload hash/version;
- lifetime and cleanup;
- states: `claimed`, `in_progress`, `succeeded`, `failed_retryable`, `failed_terminal`, `uncertain`, `reconciled`;
- duplicate response behavior;
- same key/different payload conflict behavior;
- in-progress polling/recovery behavior;
- result reference and authoritative reconciliation query.

The unique boundary is `(site, actor, logical action/version, exact resource scope, idempotency-key digest)`. Reuse with a different canonical payload fails `idempotency_payload_conflict` and performs no side effect. A duplicate after success returns the stored verified result or re-reads authority; it never executes again.

Client-generated keys may be accepted only as opaque input to a server-scoped record. The model cannot invent authority by choosing a key. Server-originated operations/callbacks/jobs use stable event/operation identifiers and the same application idempotency service.

## Locking and optimistic concurrency

- Mutable aggregates carry `resource_version` and update by compare-and-swap.
- Multi-resource sensitive actions acquire a deterministic ordered set of bounded leases or use a domain-specific authoritative lock when available.
- A lease contains resource, owner token, acquired/expiry instants, purpose, and correlation. Acquisition is bounded; release verifies owner; stale recovery is audited.
- Current versions are re-read after acquiring a lock. A lock never makes an earlier preview current.
- Confirmation and idempotency consumption use a database transaction when supported. External gateway/Woo/adaptor calls cannot be atomic with that transaction, so durable execution/reconciliation state is mandatory.
- Long external calls must not hold an unbounded database lock. Use an execution claim/lease, idempotent external key where supported, then observe/reconcile.

## Compound operations

Cart or service plans declare atomic or explicit partial semantics before execution. Each sub-operation has an exact target, dependencies, result, and side-effect count. If the authoritative API cannot provide atomicity, supported compensation may be attempted and verified; otherwise the customer receives a precise partial result. “Rolled back” is stated only after authority confirms it.

## Gateway, webhook, and callback rules

- Validate origin/signature/secret reference, event ID, resource relationship, amount/currency where applicable, time/replay window, and current gateway/order state.
- Deduplicate event and effect separately; handle duplicate, delayed, missing, and out-of-order callbacks.
- A browser return URL is not payment success. Query gateway/WooCommerce through approved contracts.
- A review approval is not payment settlement; transition request, transition result, payment status, order status, and fulfillment status remain distinct.
- Callback processing uses current feature/policy and application idempotency. Queue exactly-once delivery is never assumed.

## Uncertain outcomes and recovery

An outcome is uncertain when a timeout/disconnect/process crash occurs after execution may have started and no verified result is available.

1. Mark the operation `uncertain` with correlation and the exact known boundary; do not report success or offer blind retry.
2. Stop duplicate execution by preserving the idempotency claim.
3. Query the authoritative system by provider operation ID, order/payment/refund/case/review identifiers, and current state.
4. Classify: definitely succeeded, definitely absent/not started, failed terminal, still pending, or still unknown.
5. Reconcile local journey, confirmation, idempotency, audit, historical result, and dependent resources.
6. Tell the customer what is known, what remains unknown, whether a record exists, what was saved/changed, and whether retry is safe.
7. Permit retry only after `definitely absent/not started` or a documented idempotent authoritative retry contract.
8. Escalate persistent unknown financial/order outcomes to the incident/human process without fabricating a state.

## Stable result families

- `succeeded` — authority verified the exact intended effect.
- `partial` — named elements succeeded/failed and current state is verified.
- `failed` — no intended effect or a verified terminal failure.
- `blocked` — policy/dependency/authorization prevents execution without side effect.
- `stale` — current state invalidated preview/confirmation.
- `uncertain` — an effect may exist; reconcile before retry.
- `conflict` — concurrent version or idempotency-payload mismatch.

## Required tests

Duplicate click, two tabs, model/tool retry, voice duplicate, same key/different payload, stale summary, expired token, price/stock/cart/address/branch/rate/tax/payment/order/policy change, concurrent staff/customer action, callback duplicate/reorder, queue retry, timeout before call, timeout after authoritative success, process crash between external result and local persistence, lost HTTP response, lock expiry, dead worker, and recovery must assert authoritative state, side-effect count, audit, history, and customer-visible mapping.

