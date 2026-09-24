# Changelog

## 1.0.0 — 2026-09-24

First release, install contract v1.2.

- The contract tag in `<head>` on the public site: `data-ds-site-key`, `data-ds-environment`,
  `data-ds-build` and `data-ds-installer="wordpress-plugin/1.0.0"`, `async`, exactly once.
  Enqueued as a normal script handle and printed through `script_loader_tag`, so the tag is the
  contract's byte for byte on every WordPress version from 6.0.
- Never in wp-admin unless "Also load in the admin area" is on; never on the login page.
- Build marker: `DEPLOYSEAL_BUILD` constant, WordPress + theme version, git SHA file, or manual.
- Settings → DeploySeal: Essentials, "What this site will send", Advanced and Platform inventory.
- Platform inventory: "Send inventory now" and a six-hourly WP-Cron send.
- Multisite (per-site settings, network activation), WooCommerce HPOS and cart/checkout blocks
  compatibility declared.
