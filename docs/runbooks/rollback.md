# Rollback and reconciliation runbook

Status: design only. Exact deployment/backup platform and rollback authority are Open Decisions.

## Principle

Rollback stops new unsafe behavior first, then restores a known compatible code/config/provider state, then reconciles authoritative commerce/external effects. It never assumes database, gateway, order, payment, case, review, or provider operations can be reversed by deploying old code.

## Immediate containment

1. Declare incident/rollback with UTC time, trigger, affected build/config/provider/feature and owner.
2. Set the smallest affected feature/provider route Blocked or select an already certified immutable prior publication.
3. Stop new writes; expire relevant confirmations; pause/cancel/supersede affected queue groups and webhook processing without dropping evidence.
4. Preserve logs/audit/correlation/migration checkpoints and current backups.
5. Query authoritative state for any in-progress/uncertain operation before retry or compensation.

## Select rollback type

- Configuration/provider rollback: activate a previously validated immutable publication; re-evaluate every feature/dependency exposure.
- Application rollback: deploy the signed prior package only if its schema compatibility window includes the current database/configuration.
- Forward fix: preferred where schema/external effects make old code unsafe.
- Data restore: last resort for verified corruption, approved by data/privacy/commerce owners; never overwrite valid newer WooCommerce commerce indiscriminately.

## Schema safety

Read migration status and post-checks. Never run an unreviewed down migration. Additive/forward-compatible data normally remains. For interrupted migration, use its idempotent resume or explicitly approved recovery step. For destructive change, use the tested backup/restore and reconciliation plan. Keep affected features Blocked until repositories pass probes against the selected code/schema.

## External-effect reconciliation

For each operation in the rollback window:

- classify through WooCommerce/gateway/approved adapter as succeeded, absent, failed, pending, or unknown;
- deduplicate callbacks/jobs using original idempotency/correlation;
- reconcile local confirmation/idempotency/journey/case/review/history/audit;
- use approved commerce adjustment routes for refund/payment/order correction; never direct-write storage;
- inform customers truthfully of current state and any separate corrective action.

## Verification before reopen

- Storefront/admin smoke and no PHP fatal.
- Actor/capability/ownership/feature denial.
- Schema/repository/migration post-check.
- Provider route blocked or ready as intended; no unapproved transmission.
- Catalog/cart/checkout/order authoritative parity for affected scope.
- Confirmation/idempotency/queue/callback/reconciliation regression.
- Protected files and privacy controls.
- Arabic/RTL/accessibility critical smoke.
- Monitoring/alerts/backlog and incident ownership.

## Re-enable

Re-enable gradually through the rollout stages. Only an authorized validated publication may change effective state. Do not replay customer messages, confirmations, uploads, or failed side effects. Ordinary unsent drafts may be restored only without automatic execution.

## Record

Capture trigger, exact packages/configs/routes, containment, schema decision, authoritative reconciliation counts, customer impact/communication, verification evidence, approvals, remaining blocked features, and follow-up corrective work. Release evidence remains NOT READY until the full gate set is re-run and accepted.

