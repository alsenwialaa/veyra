# Incident response runbook

Status: owner/on-call/communications/provider contacts and recovery objectives are Open Decisions. This procedure is not operationally accepted until exercised.

## Trigger classes

Immediate P0/P1 response is required for cross-customer access, unauthorized or duplicate commerce/financial write, fabricated success, confirmation bypass, payment/secret exposure, public evidence access, destructive migration/data loss, unapproved provider transmission, critical accessibility block, widespread checkout/storefront fatal, or irreconcilable gateway/order uncertainty.

High-severity triggers include wrong WooCommerce truth, stale/wrong-resource binding, broken idempotency/lock, unsafe tool/provider contract, customer-action bypass, decision/execution collapse, missing mandatory core path, or persistent queue/backlog causing incorrect service state.

## First 15 minutes

1. Open an incident with authoritative UTC start time, severity, incident lead (Open), security/privacy/commerce/operations roles (Open), affected store/build/config/provider/feature versions, and correlation IDs.
2. Preserve evidence; do not expose customer content in the incident channel. Record safe IDs, hashes, result codes, timestamps, route/tool versions, and relevant audit references.
3. Stop duplicate/new effects at the narrowest safe gate: set affected feature/route Blocked, revoke provider route, pause specific queue group/webhook, expire confirmations, or put the plugin in declared safe mode. Do not disable native WooCommerce unnecessarily.
4. For any possible write, query authoritative WooCommerce/gateway/adapter state before retry or rollback.
5. Contain credential/file exposure: revoke/rotate secret references or access tokens, block delivery endpoint, preserve forensic copies under approved control, and stop external transmission.
6. Notify internal stakeholders and processors according to the unapproved communication matrix; do not make unsupported customer/legal claims.

## Diagnosis

- Freeze exact code/build and immutable configuration/provider publication versions.
- Identify entry point, actor, scope, feature/effective state, tool, confirmation/idempotency/lock, queue/callback sequence, authoritative calls, result mapping, historical output, and audit.
- Determine affected subjects/resources/time range using actor-scoped queries. Never broaden access or dump raw databases into general logs.
- Classify each operation as succeeded, absent/not started, failed terminal, pending, or uncertain using authority—not local prose.
- Test the smallest reproduction in an isolated representative environment with sanitized fixtures.

## Commerce reconciliation

For every affected cart/order/payment/refund/subscription/loyalty/return/case/review:

1. Read current authoritative WooCommerce/approved-adapter state.
2. Compare intended canonical payload, confirmation hash, idempotency record, callbacks/jobs, order notes, and customer-visible result.
3. Do not automatically reverse a valid external effect. Use approved refund/payment/order procedures and human review.
4. Repair local Veyra state/history only with an attributable reconciliation event; historical customer-visible content is not silently rewritten.
5. If customer-facing correction is required, show the previous state and corrected current state separately with clear authorship.

## Privacy/security response

- Establish categories, subjects, processors, regions, retention, encryption/access, and duration.
- Revoke unauthorized access and invalidate cached/signed artifacts.
- Propagate containment/deletion to derivatives only under approved legal/forensic direction.
- Engage privacy/legal authority (Open) for notification assessment; engineering does not invent legal deadlines or statements.

## Recovery gate

Restore only after the defect is fixed at the smallest safe boundary; deterministic regression plus integration/E2E/security tests pass; affected state is reconciled; queue/callback backlog is reviewed; provider/feature/config publication is explicitly approved; monitoring/alerts are active; rollback remains available; and incident lead plus required owners accept residual risk.

## Required customer/merchant truth

State what failed, what still worked, whether data was saved, whether a write/record exists, current authoritative state, whether retry is safe, and next step. Never call an uncertain result success or promise compensation/settlement before authority confirms it.

## Closure

- Timeline with UTC authoritative times.
- Root cause and contributing control/test/monitoring gaps.
- Affected resources and completed reconciliation.
- Credential/data/customer communication actions.
- Fix commit/build/config and regression evidence.
- Traceability/threat/DoD/runbook updates.
- Follow-up owners/dates and a game-day/reoccurrence test.
- Blameless review without weakening severity or release gates.

