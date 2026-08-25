# Veyra AI Commerce Agent for WooCommerce

[![Candidate quality checks](https://github.com/alsenwialaa/veyra/actions/workflows/quality.yml/badge.svg)](https://github.com/alsenwialaa/veyra/actions/workflows/quality.yml)
[![WordPress and WooCommerce integration](https://github.com/alsenwialaa/veyra/actions/workflows/platform-integration.yml/badge.svg)](https://github.com/alsenwialaa/veyra/actions/workflows/platform-integration.yml)

Veyra is a chat-first WooCommerce commerce-agent engineering candidate built around typed tool contracts, WooCommerce authority, customer isolation, confirmation/idempotency controls, and fail-closed release gates.

> **Release status: NOT READY for production.** Shopper AI transmission and incomplete or uncertified capabilities remain blocked by design. See [release evidence](docs/release-evidence.md) for the exact tested boundary and unresolved gates.

## Requirements

- WordPress 6.5 or newer
- WooCommerce 8.5 or newer
- PHP 8.1 or newer

## Development checks

Install development dependencies with Composer, then run:

```bash
composer validate --strict --no-check-publish
composer install
npm ci
composer test:all
composer analyse
npm test
python3 scripts/verify_release.py
python3 scripts/package_release.py --output build
```

Candidate [`c8a46aa`](https://github.com/alsenwialaa/veyra/commit/c8a46aa73974af81fb1fb5e5831ab9b203cc3a43) passes [9/9 quality jobs](https://github.com/alsenwialaa/veyra/actions/runs/32797874762) and [4/4 platform/browser jobs](https://github.com/alsenwialaa/veyra/actions/runs/32797874806): PHP 8.1–8.4 contracts, Plugin Check with 0 errors/0 warnings, three exact WordPress/WooCommerce/database cells, reproducible packaging, and 2/2 isolated Chromium/axe tests. These bounded checks and smokes do not establish broad support or release acceptance; see the candidate report for the exact boundary.

Protected-media routes are fail-closed. A deployment that intends to enable them must define `VEYRA_PROTECTED_STORAGE_PATH` as an absolute non-public directory, install an approved scanner through `veyra_malware_scanner_callback`, and explicitly set `VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS` to an approved whole number from 3,600 through 31,536,000 seconds. Veyra supplies no guessed retention default.

## Architecture and governance

- [Architecture](docs/architecture.md)
- [Feature registry](docs/feature-registry.md)
- [Logical tool catalog](docs/logical-tool-catalog.md)
- [Threat model](docs/threat-model.md)
- [Test strategy](docs/test-strategy.md)
- [Open decisions](docs/open-decisions.md)
- [Canonical proposal traceability](docs/traceability/README.md)

## License

GPL-2.0-or-later. See the plugin header and WordPress readme for package metadata.
