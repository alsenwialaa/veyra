=== Veyra AI Commerce Agent for WooCommerce ===
Contributors: veyra
Tags: woocommerce, ai, conversational-commerce, customer-support
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-led conversational commerce infrastructure for WooCommerce with typed tools, backend authority, customer isolation, and fail-closed gates.

== Description ==

Veyra is an engineering candidate for a chat-first WooCommerce commerce agent. It includes actor-scoped conversations, context and memory contracts, catalog and cart tools, preview-only checkout, owned-order reads, CRM/payment-review drafts, a Google Gemini adapter, merchant administration, and accessible customer UI foundations.

This package is NOT READY for production. Shopper AI transmission is disabled in the checked-in provider manifest until capability, privacy, transmission, evaluation, and release-certification gates all pass. Incomplete sensitive operations and protected-media flows remain blocked.

See `docs/release-evidence.md` for the exact validation record and open gates.

== Installation ==

1. Upload the `veyra-ai-commerce-agent` folder to `/wp-content/plugins/`, or install the provided plugin ZIP.
2. Activate the plugin in WordPress.
3. Confirm WordPress 6.5+, PHP 8.1+, and WooCommerce 8.5+ are present.
4. Review the Veyra administration state and release evidence before configuring a provider credential.

Activation performs no provider call. A credential and successful capability probe do not, by themselves, enable shopper traffic.

== Frequently Asked Questions ==

= Does this package enable AI chat immediately? =

No. The checked-in route is Unconfigured and uncertified. Veyra fails closed until the independent release predicate is satisfied.

= Does it place orders or approve offline payments? =

No. Order placement, payment approval, reviewer decisions, and other incomplete sensitive mutations are not exposed as working capabilities.

= Is HPOS compatibility declared? =

No. The implementation uses WooCommerce CRUD APIs for order reads, but HPOS compatibility must be tested before declaration.

== Changelog ==

= 0.1.7 =

* Added schema 1.6.0 so Conversation Focus persists its bounded unresolved-reference set; actor ownership, exact compare-and-set behavior, migration postconditions, and privacy export now cover that field.
* Serialized cart and checkout writes through one actor-wide WooCommerce authority lease, strengthened reconciliation/idempotency failure truth, and blocked arbitrary shipment-tracking extension payloads until a typed adapter is published.
* Closed additional catalog and recommendation result contracts for safe provider projection, enforced live Woo quantity/stock constraints, rejected undeclared Gemini shopper tool calls, and preserved exact registered ToolResult shapes on semantic replay.
* Made runtime and security-lifecycle composition fail closed without partial hooks, removed guessed protected-media retention, verified protected attachment bytes before delivery, and strengthened privacy/deactivation/uninstall recovery behavior.
* Added Draft 2020-12 schema compilation, PHPStan, Plugin Check, PHP 8.1–8.4, WordPress/WooCommerce/MySQL/MariaDB/HPOS/classic/Blocks, browser, accessibility, coverage, and deterministic-package workflows. Formal acceptance and live/provider gates remain unresolved, so this candidate is still NOT READY.

= 0.1.6 =

* Added schema 1.5.0 with an actor-scoped, metadata-only Context Bundle manifest ledger. Provider transmission now requires a verified manifest write; privacy export, erasure, legal-hold-aware cleanup, retention eligibility, uninstall inventory, and authenticated guest-account re-keying include the new table.
* Added a deterministic prohibited-data classifier/redactor and closed provider-safe ToolResult projection. Raw result bodies, correlation IDs, credentials, payment-card data, one-time codes, banking secrets, and unsupported result shapes fail closed before another provider call.
* Updated the raw Gemini Interactions adapter for the current `steps` response shape, structured `response_format`, and stateless `store=false` requests; legacy `outputs` and SDK-only `output_text` aliases are rejected.
* Required exact product and variation targets for product/comparison components, grounded cards only in named authoritative catalog result shapes, and strengthened exact WordPress/WooCommerce customer-session binding for cart and checkout paths.
* Kept shopper transmission, every provider certification flag, all commerce-tool promotions, and every formal acceptance item disabled. This candidate remains NOT READY for production.

= 0.1.5 =

* Replaced the legacy Context Bundle payload with a closed 1.1.0 provider projection containing pseudonymous actor scope, selected data, complete included-source metadata, per-section selection/exclusion decisions, privacy policy binding, expiry, and stable whole-bundle byte/item accounting.
* Bound current input to the exact actor-owned persisted customer message, reloaded explicit quote/product-reference sources from actor-owned history, omitted raw render/evidence/correlation data and unvalidated memory/summary/media/location state, and reduced optional history deterministically before failing closed on mandatory overflow.
* Added one immutable Context Bundle digest across decision, response, and semantic-verification calls plus a typed send-time provider transmission gate that rechecks release state immediately before credential and network access.
* Added content-free success/failure message correlation, exact nested unknown-field rejection, cross-actor, truncation, tamper, readiness-isolation, revoked-state, and zero-provider-call denial coverage.
* Kept shopper transmission disabled and the database schema at 1.4.0. Durable Context Bundle manifest storage, validated memory/summary lifecycle, commerce response grounding, live provider/WooCommerce matrices, and all formal acceptance remain open; this candidate is NOT READY.

= 0.1.4 =

* Added schema 1.4.0 and a dedicated actor-owned requirement-state head with complete-history hashing, unique empty-head creation, and version/hash compare-and-swap.
* Preserved bounded 0.1.3 requirement history through a race-safe actor-owned lazy import; added account-link re-keying, privacy export/erasure, uninstall inventory, and migration contracts.
* Removed caller-authored requirement arrays and ranking scores from filtering, ranking, diversification, and explanation; every evaluation now binds to and rechecks one exact server-owned requirement snapshot.
* Added the requirement snapshot and exact state reference to the provider context while excluding the retained legacy copy as a competing source.
* Added closed requirement read/write contracts and deterministic ownership, provenance, correction, stale-state, and two-writer race coverage.
* Kept these tools ineligible for production governance pending live WordPress/MySQL/WooCommerce evidence. No commerce tool was certified, and the release remains NOT READY.

= 0.1.3 =

* Replaced the legacy combined shopper turn with strict decision, server plan execution, response, deterministic verification, and semantic verification phases.
* Added AI-proposed/server-validated short-reply binding with exact focus/resource/dependency checks and one-time transactional Pending Question consumption.
* Added schema 1.3.0, upgrade-safe runtime gates, replacement invalidation, replay tests, exact quick-reply input bounds, and expanded deterministic contracts.
* Added closed per-tool output validation and individually tested `context.get_runtime_clock` planning/execution; all 154 other catalog rows remain uncertified.
* Serialized configuration revisions, publish, and rollback; required complete commerce feature maps; and added deterministic concurrency/atomicity fixtures.
* Hid the public chat surface while AI is blocked, rejected protected storage under the document root and network-wide activation, and made browser mutation IDs fail closed without Web Crypto.
* Preserved every live/formal release gate. This remains an engineering candidate and is NOT READY for production.

= 0.1.2 =

* Added fail-closed catalog-backed tool governance, strict decision/response/result contracts, and deterministic AI-context regression coverage.
* Tightened exact variation resolution, recommendation evidence, cart-clear confirmation/replay handling, checkout authority, and owned-order safety contracts.
* Added independently gated protected-media REST foundations, privacy exporter/eraser and retention wiring, administrative idempotency, and lifecycle cleanup.
* Added complete Arabic customer-interface catalogs, deterministic static release verification, CI contracts, and reproducible source/installable packaging.
* Preserved the provider, optional-module, and production release gates. This remains an engineering candidate and is NOT READY for production.

= 0.1.1 =

* Preserved exact stateless Gemini function-call continuation history and added strict REST response normalization.
* Tightened catalog visibility, recommendation evidence authority, focus-resource binding, persistence postconditions, migration locking, and uncertain CRM write reconciliation.
* Prevented permission checks from creating guest sessions, enforced attachment expiry, and made protected-data uninstall fail closed.
* Kept unbound memory/requirement updates and pending-answer mutations fail closed; added exact cart postcondition and compound-conflict verification.
* Added index/engine migration verification, bounded migration retry exhaustion, and unified local contract-test scripts.
* Added focused deterministic regression runners. This remains an engineering candidate and is NOT READY for production.

= 0.1.0 =

* Initial engineering candidate based on the canonical Veyra v4.1 proposal.
* Added fail-closed Gemini orchestration and provider release gate.
* Added actor-scoped persistence, deterministic confirmation/idempotency foundations, commerce handlers, administration, UI contracts, and deterministic tests.

== Upgrade Notice ==

= 0.1.7 =

Runs schema 1.6.0 migration for Conversation Focus unresolved references. Protected media now requires an approved `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS`. This engineering candidate remains NOT READY and not production-certified.

= 0.1.6 =

Runs schema 1.5.0 migration for metadata-only Context Bundle manifests. Retention and legal-hold policy remain operator decisions. Live database, privacy, WooCommerce, provider, and formal acceptance gates remain open; this candidate is NOT READY.

= 0.1.5 =

No database migration is added; schema remains 1.4.0. Context Bundle wire shape changes to 1.1.0 and provider calls now require its exact hash-bound, send-time-authorized projection. Review `docs/review-and-implementation-report-0.1.5.md`; this version is NOT READY and is not production-certified.

= 0.1.4 =

Runs the bounded schema 1.4.0 requirement-state migration. Existing bounded conversation requirements are imported through an actor-owned compare-and-swap path when first read. Review `docs/review-and-implementation-report-0.1.4.md`; this version is NOT READY and is not production-certified.

= 0.1.3 =

Runs the bounded schema 1.3.0 Pending Question binding migration. Review `docs/review-and-implementation-report-0.1.3.md`; this version is NOT READY and is not production-certified.

= 0.1.2 =

Engineering candidate only. Review `docs/review-and-implementation-report-0.1.2.md`; this version is NOT READY and is not production-certified.

= 0.1.1 =

Engineering candidate only. Review `docs/release-evidence.md`; this version is NOT READY and is not production-certified.
