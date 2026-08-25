# Runtime source verification — 2026-08-24

## Evidence boundary

This record verifies current public API documentation used by the candidate. It
does not certify a live provider route, WooCommerce compatibility, HPOS, or the
release. Capability probes, integration matrices, evaluations, and acceptance
remain separate mandatory evidence.

## Google Gemini

Official sources reviewed:

- <https://ai.google.dev/gemini-api/docs/interactions-overview>
- <https://ai.google.dev/gemini-api/docs/models/gemini-3.7-flash>
- <https://ai.google.dev/gemini-api/docs/function-calling>
- <https://ai.google.dev/gemini-api/docs/structured-output>

Verified facts used by `config/provider-route-manifest.php`:

- the Interactions API is generally available and recommended for new projects;
- Interactions stores requests by default, so Veyra explicitly requests
  stateless behavior and must keep `store=false` effective end to end;
- `gemini-3.7-flash` is a stable model identifier;
- the exact model advertises function calling and structured output; and
- the exact model does not provide Live API support, so real-time voice cannot
  be certified through this route.

The checked-in route remains `Unconfigured`, shopper transmission disabled,
privacy publication false, evaluation false, and release certification false.
This documentation check cannot change those states.

## WooCommerce

Official sources reviewed:

- <https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/>
- <https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/>
- <https://woocommerce.github.io/code-reference/>

Verified implementation constraints:

- order reads and writes must use supported WooCommerce CRUD/query and public
  customer-action contracts rather than posts/postmeta assumptions;
- the public compatibility declaration remains
  `FeaturesUtil::declare_compatibility('custom_order_tables', ..., true)`; and
- Veyra must not emit that declaration until the declared HPOS matrix actually
  passes. The current candidate therefore makes no HPOS compatibility claim.

## WordPress REST API

Official sources reviewed:

- <https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/>
- <https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/>
- <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>

Every Veyra endpoint must have an explicit permission callback. A nonce is a
CSRF/replay input, not authorization; actor, capability, feature, ownership,
rate, state, and idempotency rules remain server-side.

## Release action

Repeat this verification at release time, run the exact route and compatibility
matrices, update the provider/compatibility manifests, and retain attributable
test results. Any changed API, model capability, retention behavior, or
deprecation reopens the corresponding release gate.
