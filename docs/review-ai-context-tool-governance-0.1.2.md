# AI, context, and tool-governance review — 0.1.2 bounded batch

Date: 2026-08-24  
Scope: AI provider contracts, logical-tool registry governance, typed tool output, Pending Question review, response truth, and deterministic regression evidence. Commerce-domain handlers were not changed.

## Outcome

This batch closes the contract/parser and universal registry-boundary gaps without claiming that the shopper orchestration is production-complete.

- Added exact, versioned runtime validators and JSON Schema artifacts for the interpretation/plan decision envelope, shopper response envelope, and logical Tool Result envelope.
- Added provider-adapter routing for `agent_decision_v1` and `agent_response_v1`; invalid, oversized, extra-field, and cross-phase payloads fail closed.
- Expanded the bounded JSON Schema subset used by tool inputs to support `const`, union types, `oneOf`, `uniqueItems`, and property-count limits.
- Added a catalog-backed governance gate shared by model discovery and execution. Missing, invalid, version-mismatched, classification-mismatched, open-input, or uncertified contracts are not exposed and cannot execute.
- Added Tool Result binding to schema version, tool version, call ID, tool name, and correlation ID, plus size and classification invariants. Invalid read output becomes a failed result; invalid write output becomes uncertain.
- Preserved the existing deterministic evidence verifier and independent prose verifier. Provider function results include the versioned public result fields but exclude the internal correlation ID.

## Release-blocking findings still open

1. `CommerceAgent` still requests the legacy combined `agent_turn_v1` envelope. The new strict decision and response contracts are routed by the provider adapter but are not yet the shopper-turn execution path. Consequently interpretation, ordered plan, and response are not runtime-separated end to end.
2. Pending Question semantics remain client-supplied through `answer_binding`. The store has no atomic compare-and-set consumption record, replacement invalidation, or AI-proposed short-reply binding promotion. The current agent correctly keeps every active Pending Question turn read/advisory only, so writes are blocked rather than replayable, but the intended short-reply flow is incomplete.
3. Every entry in `logical-tool-catalog.json` remains `contracted_not_implemented`. Production construction now treats that status as uncertified, so no catalog tool is provider-visible or executable until each implemented vertical slice has exact schemas and test/acceptance evidence. This is deliberate fail-closed behavior, not a release acceptance claim.
4. The generic catalog still does not provide resolved per-tool ownership, market, autonomy, consent, dependency, and rate-policy implementations. The governance layer enforces the catalog fields it can resolve (actor, authentication, capability, feature, release unit, version, classification, exposure, closed input); the other policies need server services and evidence per tool.
5. Context Bundle runtime annotations remain thinner than the rich canonical context-bundle schema. Actor scoping and bounds exist, but source classification, source version, and freshness metadata are not complete across every entry.

## No semantic regex fallback finding

No regex/keyword/fixed-dialogue semantic fallback was found in the reviewed AI, Conversation, Context, Memory, or Requirements paths. Regular expressions in scope validate identifiers, decimal representations, JSON pointers, and schema patterns only. Provider/semantic-verifier failure remains a declared blocked/unavailable result.

## Deterministic evidence

Commands executed with the available PHP 8.2 WebAssembly runner:

```text
/workspace/scratch/f55968984e46/tools/php tests/AI/run-ai-context-governance.php
AI/context governance scenarios: 8 passed, 0 failed

/workspace/scratch/f55968984e46/tools/php tests/run-foundation.php
Foundation scenarios: 31 passed, 0 failed
```

The focused suite proves exact-field/version rejection, strict phase separation at the parser, Tool Result versioning, read-output mutation rejection, and catalog certification denial at both discovery and execution.

## Traceability disposition

| Proposal concern | Evidence in this batch | Disposition |
|---|---|---|
| Separate typed interpretation and plan | `agent-decision.schema.json`; `ProviderPayloadValidator::validateDecisionPayload()` | Contract/parser complete; orchestration wiring open |
| Separate typed response | `agent-response.schema.json`; `validateResponseContractPayload()` | Contract/parser complete; orchestration wiring open |
| Typed versioned Tool Result | `tool-result.schema.json`; `ToolResult`; `ToolResultValidator` | Runtime boundary enforced |
| Universal registry governance | `UniversalToolGovernance`; `ToolRegistry` discovery/execution gates | Fail-closed; per-tool certification open |
| Pending Question short replies | Existing `ShortReplyBindingValidator` and active-question mutation denial | AI proposal/CAS/invalidation open |
| Verified response truth | Existing `ResponseVerifier` + `SemanticResponseVerifier`; versioned result input | Enforced for current legacy response path |
| No regex semantic fallback | Scoped source review | No fallback found |

No central release-evidence or traceability ledger was rewritten by this bounded batch.
