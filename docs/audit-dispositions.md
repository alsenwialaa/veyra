# Heuristic audit dispositions

Audit date: 2026-08-25. Candidate: `0.1.7`. Tool: the Veyra engineering skill's packaged heuristic repository audit.

Result: **0 critical, 16 high, 34 medium** across the scanner's repository scope. The scanner is intentionally broad; these dispositions do not substitute for an independent security assessment.

## HIGH signals

Fifteen are regular-expression heuristic signals. Manual review found bounded syntax/envelope validation, secret classification/redaction, or test assertions—not hidden intent, product, order, journey, tool, or response routing. Relevant cases include:

- readiness nonce and identifier validation;
- cart-hash control-character rejection;
- currency, status, decimal, opaque-ID, and timestamp validation;
- credential/password/OTP/CVV/token/IBAN/card/government/medical prohibited-data detection;
- prompt/context tests that assert isolation and omission.

The remaining HIGH signal is restricted, test-only PHP deserialization in a fake database used to verify private WordPress option persistence; class instantiation is disabled. Production configuration code compares the exact serialized bytes read from its private option row and does not deserialize that value.

## MEDIUM signals

Fifteen raw-provider-field-word signals are ordinary words in adapters, contracts, fixtures, or documentation such as candidate/function-call terminology. Provider-specific decoding remains contained in the Gemini adapter, and the transmission gate checks the exact final request body.

Nineteen possible first-result signals were reviewed. Production selection is guarded by an exact-match/cardinality check, server-ranked candidate set, or exact product-reference token/tuple match before indexing. Test assertions and fixture list access are not resource selection. This disposition does not certify product grounding; live WooCommerce and extension behavior remains unassessed.

The scanner also reports HPOS signals from documentation/source discussion. The executable plugin deliberately registers no HPOS compatibility declaration. WooCommerce CRUD/public APIs are used for order/product authority, but HPOS compatibility remains blocked until the live matrix passes.

## Outcome

No heuristic critical signal exists, and every reported high category has a recorded manual disposition. That is not proof of absence of vulnerabilities. The scan also cannot prove business-semantic correctness, authorization completeness, data isolation, WooCommerce parity, or provider safety. Live WordPress/WooCommerce/MySQL/Gemini integration, provider/privacy policy, complete ToolResult projection acceptance, snapshot freshness, guest session binding, protected-media deployment, independent security testing, and formal acceptance remain blockers in `release-evidence.md`.
