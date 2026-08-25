# Production rollout runbook

Status: design only. Release owner, store cohort, maintenance window, SLO, backup/restore owner, monitoring thresholds, and communication plan are Open Decisions.

## Entry criteria

Rollout is prohibited until the release evidence verdict is READY: every mandatory gate Passed; no Critical/High blocker; all 35 anchors and 64 DoD items accepted; exact default Gemini route independently passes; compatibility/performance/accessibility/security/privacy/migration/operations evidence is attributable; backup/restore and rollback are exercised.

## Preflight

1. Freeze signed/reproducible package, commit/build hash, proposal/schema/config/provider manifest versions, SBOM/dependency scan, and package inventory.
2. Verify the proposal checksum and release-evidence approvals.
3. Verify supported WordPress/PHP/database/WooCommerce/HPOS/Blocks/classic/theme/gateway/adapter matrix on the target store.
4. Take and verify restorable database and protected-file/config backups according to the approved plan.
5. Confirm queue runner, cron, storage, file scanner, cache, clock/time zone, monitoring, alerting, logs/redaction, incident contacts, and rollback access.
6. Confirm provider credential reference/region/privacy/retention, exact route/model, readiness freshness, budget/timeout/circuit, and explicit fallback policy. No activation-time remote call.
7. Export current Veyra configuration and record native WooCommerce baseline metrics/checkout tests.

## Staged deployment

| Stage | Exposure | Required observation before advance |
|---|---|---|
| 0 | Package install/activation with customer AI blocked | No fatal/storefront regression; bounded schema; no provider transmission; health diagnostics correct |
| 1 | Internal administrators/simulation fixtures only | Provider/tool/schema tests, actor/capability denial, Arabic/RTL/accessibility smoke, queue/files/rollback |
| 2 | Staff/allowlisted test customers on non-production or shadow read-only flows | No cross-customer access; current Woo truth; no side effect in read-only; performance budgets |
| 3 | Small production cohort with low-risk reads; writes individually gated | Error/denial/latency/quality/cost; customer support readiness; exact historical rendering |
| 4 | Gradual catalog/cart exposure | Cart parity, side-effect count, checkout invalidation, no fabricated result |
| 5 | Checkout/order/CRM/payment review only after domain-specific sign-off | Confirmation/idempotency/gateway/reconciliation and staff separation stable |
| 6 | Broader Production Core | All SLO/quality/privacy/commerce thresholds stable for approved window |

Optional modules are never enabled as part of a generic core rollout. Each needs its own module certificate and staged plan.

## Live monitoring

Monitor privacy-minimized counts/rates by build/config/route/tool/feature: storefront/PHP errors; permission/ownership denials; model schema/tool/verification failures; fabricated-success sentinel; duplicate/idempotency/lock conflicts; uncertain outcomes; cart/checkout/order parity; gateway callback lag; queue backlog/dead-letter; file scanner/storage; context/focus/summary drift; repeated questions/wrong binding; cross-customer sentinel; p50/p95/p99 latency; cost/budget/circuit; accessibility/customer support reports.

## Stop/rollback triggers

Immediate stop: any cross-customer leak, unauthorized/duplicate write, confirmation/media bypass, fabricated success, secret/payment exposure, public file, destructive migration, unapproved transmission, critical storefront or mandatory accessibility failure. Also stop on persistent wrong totals/actions, provider quality below floor, unresolved gateway uncertainty, migration error, queue runaway, or breached approved SLO threshold.

## Advance/hold decision

Only the named release authority advances a stage after engineering, security, privacy, commerce, accessibility, operations, and product owners sign the observed evidence. Absence of alerts is not proof. Hold when a required owner/evidence/health result is missing or stale.

## Completion

Record actual cohort/times, target environment, migration checkpoints/post-checks, provider readiness/route, health and scenario results, incidents/holds, final monitoring window, and rollback readiness. Update compatibility and release evidence; do not broaden claims beyond tested scope.

