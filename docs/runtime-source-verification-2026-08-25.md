# Runtime source verification — 2026-08-25

## Evidence boundary

This record checks the current public contracts that constrain the candidate's
runtime code. It is documentation review, not a live provider, WordPress,
WooCommerce, Action Scheduler, HPOS, privacy, compatibility, or release test.
Every corresponding live matrix and named acceptance remains mandatory.

## Google Gemini Interactions API

Official sources reviewed:

- <https://ai.google.dev/gemini-api/docs/interactions-overview>
- <https://ai.google.dev/gemini-api/docs/get-started>
- <https://ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026>
- <https://ai.google.dev/gemini-api/docs/function-calling>
- <https://ai.google.dev/gemini-api/docs/structured-output>

Current constraints applied to the raw REST adapter:

- Interactions stores requests by default; Veyra must keep `store=false`
  effective in the finalized request and cannot silently inherit provider-side
  conversation state.
- The post-May-2026 REST response uses the typed `steps` timeline. The legacy
  `outputs` schema was removed on 2026-06-08 and the `Api-Revision` header is
  ignored after that sunset, so raw REST decoding must not accept a legacy
  response as successful evidence.
- Text structured output uses the polymorphic
  `response_format={"type":"text","schema":...}` shape.
- Stateless continuation must preserve the provider's returned steps exactly;
  the current shopper orchestration instead uses bounded independent phases and
  does not enable an unreviewed continuation loop.

The checked-in route remains `Unconfigured`, stateless, transmission-disabled,
privacy-unpublished, unevaluated, and uncertified. This source review does not
permit credentials, shopper transmission, or a release-state change.

## WooCommerce and HPOS

Official sources reviewed:

- <https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/>
- <https://developer.woocommerce.com/docs/extensions/best-practices-extensions/compatibility/>
- <https://woocommerce.github.io/code-reference/>

Current constraints:

- Order access must use WooCommerce CRUD/query and current public customer
  action APIs instead of direct `posts` or `postmeta` assumptions.
- A compatibility declaration is a statement backed by testing, not a way to
  make code compatible. Veyra must not call
  `FeaturesUtil::declare_compatibility('custom_order_tables', ..., true)` until
  its HPOS-on/off and synchronization matrix passes.
- Product, variation, visibility, stock, cart, shipping, tax, fee, gateway, and
  order-action facts remain WooCommerce-authoritative at execution time.

The candidate still makes no HPOS compatibility claim.

## WordPress REST API

Official source reviewed:

- <https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/>

Every route requires an explicit `permission_callback`. Nonces and guest CSRF
tokens are request-integrity inputs, not resource authorization; actor type,
capability, feature state, ownership, current state, rate, and idempotency checks
remain server-side application requirements.

## Action Scheduler

Official source reviewed:

- <https://actionscheduler.org/usage/>

Action Scheduler initializes on WordPress `init` priority 1. Its APIs must be
called after that point or from `action_scheduler_init`; scheduled jobs also
remain application-idempotent and require live duplicate/retry/failure tests.
The candidate does not bundle or certify Action Scheduler and retains a bounded
WordPress cron fallback where documented.

## Release action

Repeat this verification against the frozen release tree. Then run the exact
provider request/response, retention, WordPress/WooCommerce/HPOS, Action
Scheduler, security, privacy, accessibility, load, recovery, upgrade, rollback,
and uninstall matrices. A changed contract, model, storage behavior,
deprecation, or compatibility surface reopens the affected gate.
