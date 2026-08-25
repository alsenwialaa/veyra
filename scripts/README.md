# Release scripts

`verify_release.py` checks static package invariants: version consistency,
canonical registry counts, JSON validity, explicit REST permission signals, and
forbidden WooCommerce authority-boundary patterns. It is deliberately not a
substitute for live WordPress/WooCommerce, provider, browser, accessibility,
security, performance, or acceptance evidence.

`package_release.py` runs those checks and produces deterministic source and
WordPress-installable ZIPs. The installable archive includes only runtime files;
the source archive also includes tests, documentation, and engineering scripts.

`source_symbol_sweep.php` registers the production PSR-4 loader and resolves
every source class, interface, trait, and enum. It is an autoloadability check,
not evidence of WordPress or WooCommerce runtime compatibility.

The repository quality workflow runs the deterministic suites across PHP 8.1,
8.2, and 8.3 and smoke-tests both archives. It is intentionally not a live
WordPress/WooCommerce compatibility, browser, provider, or release matrix.

```bash
python3 scripts/verify_release.py
python3 scripts/package_release.py --output ../release
```
