# Universal logical-tool catalog

`../config/contracts/logical-tool-catalog.json` contains **all 155** logical tools named in proposal sections 7.1–7.6. `universal-tool-contract.schema.json` defines the required resolved entry shape.

Each entry records stable name/version, owner, release unit, read/write/sensitive/advisory class, feature mapping, model-exposure state, actors/authentication, ownership and policy gates, input/output schema IDs, server-resolved fields, authoritative source, result/error families, reads/writes/invalidation, confirmation, idempotency, locking, retry and uncertain-result rules, execution authority, privacy/audit, customer mapping, compatibility, tests, and proposal/anchor traceability.

## Evidence status

Exactly one entry, `context.get_runtime_clock`, is `tested`. It is a dependency-light read that accepts an exact empty object, returns UTC/site-time fields through a closed successful-result schema, and is limited to guest/customer actors while `ai_time_awareness` is available. Its executable evidence covers catalog discovery, actor and feature denial, strict planning profile, current-time behavior, input rejection, successful output validation, and malformed-output failure.

Seven entries are `implemented_not_tested`: `requirements.get`, `requirements.propose_update`, and the five bounded recommendation operations from candidate retrieval through structured explanation. The requirements slice has closed inputs and successful outputs, actor-owned version/hash compare-and-swap state, exact message provenance, context binding, migration, privacy, and deterministic race evidence. The recommendation slice rejects caller-supplied requirements and scores, rechecks the exact requirement head after computation, and enforces closed success/stale data contracts. These seven entries remain ineligible for provider discovery and execution because live WordPress/WooCommerce/MySQL compatibility is unrun, the commerce/policy snapshot is not version-bound end to end, response-grounding certification is incomplete, and named acceptance is absent.

The other 147 entries remain `contracted_not_implemented`. Their input/output schema URNs are stable design identifiers, not completed field-level schemas. Tool presence in the catalog is not implementation, authorization, model exposure, test, or acceptance evidence. During vertical-slice implementation, each URN must resolve to an exact schema and every generic rule must be replaced or supplemented with tool-specific resources, state transitions, invalidation, adapter contracts, budgets, result codes, and deterministic tests. No additional catalog entry is certified by the 0.1.4 requirement-state slice.

## Runtime exposure algorithm

A logical tool may be declared to the model only when:

1. its Production Core implementation or optional-module certification exists;
2. configured and effective feature state permits it;
3. current provider route supports its schema/capability quality floor;
4. actor type, authentication, explicit capability where applicable, ownership, market/branch, autonomy, consent/privacy, dependency health, rate, and policy gates pass;
5. exact input/output contract versions are supported;
6. the server can resolve every resource and current-state precondition.

The model never supplies the effective actor or authorization. Tool names confer no permission. Unknown tools/versions/fields, malformed arguments, unauthorized IDs, stale state, or dependency failures are rejected before execution.

## Sensitive writes

The catalog flags proposal-sensitive execution tools, but the implementation must also elevate any merchant/adapter action whose actual impact is sensitive. Sensitive execution requires a fresh authoritative preview, exact scope/state hash, complete visible summary, one unexpired confirmation, atomic confirmation/idempotency consumption, lock/version check, authoritative execution, reconciliation, and verified result.

## Physical composition

Several logical tools may share one application service or endpoint, and one logical tool may compose approved lower-level services. Physical composition is allowed only when each logical capability remains independently traceable and preserves its actor, feature, ownership, current-state, confirmation, idempotency, audit, privacy, result, and test contract. Broad arbitrary SQL/HTTP/file/user/order tools are prohibited.
