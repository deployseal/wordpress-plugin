# DeploySeal for WordPress and WooCommerce

A WordPress plugin that loads the [DeploySeal](https://deployseal.com) tester widget on the public
site and declares **which environment** the page belongs to and **which build** is running, so the
readiness report can prove what was tested. It is one of the DeploySeal install paths and obeys the
DeploySeal install contract to the byte: the tag it emits is

```html
<script src="https://cdn.deployseal.com/ds-widget.js" data-ds-site-key="ls_…" data-ds-environment="staging" data-ds-build="7.1.2+twentytwentyfive-1.5" data-ds-installer="wordpress-plugin/1.0.0" async></script>
```

placed in `<head>`, once, on the public site only: never in wp-admin unless you opt in, never on
the login page. `data-ds-installer` names this plugin and its version as the install path, so
DeploySeal records it as the environment's install path and warns when a pasted snippet is
still live on the same environment. Remove any snippet from your theme or header
plugin once the plugin is enabled.

With an organisation API key it also reports the **platform inventory**: every installed plugin
and theme with its version and whether it is active, must-use plugins, WordPress and PHP (and
therefore WooCommerce and its extensions). The readiness report can then print exactly what was
installed when a release was tested (install contract §6).

Documentation for site owners: <https://deployseal.com/docs/wordpress>.

## Requirements

| | |
|---|---|
| WordPress | 6.0 or newer (verified end to end on 7.1.2, the current release) |
| PHP | 7.4 or newer (`php -l` in CI on 7.4, 8.1 and 8.3) |
| WooCommerce | optional; HPOS and cart/checkout blocks compatibility declared (verified with WooCommerce 11.1.2) |
| Multisite | supported: network or per-site activation, settings per site |

No Composer runtime dependencies. `composer.json` holds development tooling only (PHPCS).

## Install (site administrator)

1. Download `deployseal-<version>.zip` from the [latest release](../../releases/latest).
2. Plugins → Add New → **Upload Plugin**, pick the zip, **Install Now**, **Activate**.
3. Settings → **DeploySeal**. Only two settings are required: **Site key** and **Enabled**.
4. In DeploySeal: create an environment for the site, register the origins the settings page
   shows under *What this site will send*, copy the environment's public key into **Site key**,
   tick **Enabled**, **Save**.
5. Load the site once; the environment shows as *Live* in DeploySeal.

The WordPress.org directory listing is pending; `deployseal/readme.txt` is ready for it.

## Settings

The page reads paste key, save, confirm. **Essentials** is open; **Advanced** and **Platform
inventory** are folded `<details>` sections that open themselves as soon as anything in them is
not the default, so nothing you have set is ever hidden.

### Essentials

| Setting | Default | Meaning |
|---|---|---|
| Site key | empty | The environment's public key (`ls_…`; legacy `ds_…` keys still work). Not a secret: it only works from the environment's registered origins. |
| Enabled | off | Master switch; nothing renders while off. |

### What this site will send

The exact origins to register (the Site Address and, when it differs, the WordPress Address, as
`scheme://host[:port]`), the declared environment label and the guess it came from, the exact
build marker and its source (with the SHA file's resolved path and whether it was readable), the
WordPress, theme, WooCommerce and plugin versions, the tag byte for byte, and links to DeploySeal
and the docs.

### Advanced (folded)

| Setting | Default | Meaning |
|---|---|---|
| Environment label | empty = guessed from the Site Address: `staging` when the host contains staging/uat/test/dev/qa/sandbox/preprod/localhost, else `production` | Declared label, `[a-z0-9-]`, ≤ 32. Saved slugged. |
| Build marker source | Automatic | See below. |
| Git SHA file path | empty | File holding the deployed commit SHA (first line, 7–40 hex chars). Relative paths resolve from the WordPress root, e.g. `wp-content/build-sha.txt`. |
| Manual build marker | empty | Used by the Manual source. One token, ≤ 64. |
| Also load in the admin area | off | "Turn on only if testers need to pin issues inside the administration area too." Enqueues on `admin_enqueue_scripts` as well. The login page never loads it. |
| API base | `https://api.deployseal.com` | Where the inventory is posted. Self-hosters change it; `verify.mjs` points it at a stub. |

### Platform inventory (folded)

| Setting | Default | Meaning |
|---|---|---|
| DeploySeal API key | empty | Organisation API key with the **Write** scope. A secret: never rendered back; an empty box on Save keeps it, "Remove the stored API key" deletes it. |
| Send inventory on a schedule | off | WP-Cron event `deployseal_send_inventory` every 6 hours. "Send inventory now" works without it. |

The section also shows how many items the next send carries, the canonical fingerprint
(`sha256:…`), the exact URL, the last result, and the **Send inventory now** button.

## Build marker (install contract §4)

| Source | Marker |
|---|---|
| **Automatic** (default) | `DEPLOYSEAL_BUILD` when wp-config.php defines it; else WordPress version + active theme and its version, plus the short git SHA when the SHA file is readable: `7.1.2+twentytwentyfive-1.5+a1b2c3d` (or `7.1.2+twentytwentyfive-1.5` without one) |
| WordPress + theme version | `7.1.2+twentytwentyfive-1.5`, never a SHA |
| Git SHA only | the SHA from the file; nothing when unreadable |
| Manual | the typed value |

Whitespace is stripped, the result is cut at 64 characters, and the app snippet's placeholder
`REPLACE-WITH-BUILD-MARKER` is never emitted. Copy the marker from the settings page verbatim
into the campaign's release identifier: `a1b2c3d` does **not** confirm
`7.1.2+twentytwentyfive-1.5+a1b2c3d`, because prefix matching runs from the start of the string.
The simplest pipeline hook is one line in wp-config.php:

```php
define( 'DEPLOYSEAL_BUILD', 'a1b2c3d4e5f6' ); // written by the deploy
```

## Platform inventory (install contract §6)

`POST {API base}/api/v1/sites/{site key}/inventory`, `Authorization: Bearer <API key>`, server to
server with a 15 s timeout and one retry on a transport failure:

```json
{ "platform": "wordpress", "platformVersion": "7.1.2", "buildMarker": "7.1.2+twentytwentyfive-1.5",
  "capturedAt": "2026-09-24T20:46:44.926Z",
  "items": [ { "systemName": "core:wordpress", "name": "WordPress", "version": "7.1.2", "enabled": true },
             { "systemName": "plugin:woocommerce/woocommerce.php", "name": "WooCommerce", "version": "11.1.2", "enabled": true }, … ] }
```

System names: `plugin:<file>` (every installed plugin; `enabled` = active on this site or
network-active), `mu-plugin:<file>`, `theme:<stylesheet>` (every installed theme; `enabled` =
the active theme or its parent), `core:wordpress`, `runtime:php`. Sorted by system name (byte
order), de-duplicated, capped at 500 items and the contract's field lengths (128 / 200 / 32).
Nothing else leaves the site: no settings, users, customers or orders.

## Repository layout

```
deployseal/                        the plugin (this folder is the zip root and the WordPress.org SVN trunk)
  deployseal.php                   header, constants, bootstrap
  uninstall.php                    removes options and the cron event from every site
  readme.txt                       WordPress.org readme
  includes/class-contract.php      contract constants (host, limits, installer string)
  includes/class-snippet.php       the byte-exact tag
  includes/class-tag.php           enqueue on wp_enqueue_scripts / admin_enqueue_scripts, swap in script_loader_tag
  includes/class-build-marker.php  marker sources and resolution
  includes/class-git-sha.php       SHA file reading
  includes/class-environment-label.php, class-origins.php
  includes/class-options.php       defaults, sanitize callback, derived values
  includes/class-inventory.php     collect, canonicalise, send, WP-Cron handler
  includes/class-settings-page.php Settings → DeploySeal and "Send inventory now"
  includes/class-plugin.php        hooks, cron schedule, activation, WooCommerce compatibility
build/build.ps1                    → artifacts/deployseal-<version>.zip (forward-slash entries, deployseal/ at the root)
build/bump.ps1                     sets the version in the header, the constant, readme.txt and verify.mjs together
docker-compose.yml                 wordpress:latest + MariaDB 11 on port 8100
tests/e2e/verify.mjs               Playwright end-to-end check against that stack
phpcs.xml.dist, composer.json      WordPress Coding Standards + PHPCompatibilityWP (7.4+)
```

## Build and verify

```powershell
./build/build.ps1                                  # artifacts/deployseal-1.0.0.zip
docker compose up -d
cd tests/e2e; npm install; npx playwright install chromium; cd ../..
node tests/e2e/verify.mjs
docker compose down -v
```

`verify.mjs` completes the install wizard, uploads the zip through **Upload Plugin** (replacing
an earlier upload), activates it, configures a fake key and asserts:

- the storefront `<head>` carries exactly one DeploySeal tag, byte-identical to the contract's,
  with `data-ds-environment`, `data-ds-build` and `data-ds-installer="wordpress-plugin/1.0.0"`;
  WordPress's own `id`/`?ver=` tag never leaks through; Enabled off renders nothing;
- wp-admin has no tag by default and exactly one once *Also load in the admin area* is on;
  `wp-login.php` never has it (checked with the toggle on);
- the build marker: default, SHA file (`+a1b2c3d`), `DEPLOYSEAL_BUILD` (from a must-use plugin),
  and the placeholder never emitted;
- WooCommerce (downloaded from WordPress.org) lists the plugin as HPOS-compatible, the settings
  page shows its version, the shop page is tagged once;
- **Send inventory now** posts the contract body to a stub API on the host (through
  `host.docker.internal`): method, path, bearer key, key order, sorting, uniqueness, the plugin,
  WordPress, PHP, themes and WooCommerce present; 201 then "already on record" 200 shown on the
  page; the stored key never appears in the page; the six-hourly cron event is registered.

Environment overrides are listed at the top of the script (`DS_WP_URL`, `DS_WP_CONTAINER`,
`DS_STUB_PORT`, `DS_SKIP_WOOCOMMERCE`, `DS_E2E_NODE_MODULES`, …).

PHPCS: `composer install && vendor/bin/phpcs` (or through the `composer:2` Docker image).

## Releasing

```powershell
./build/bump.ps1 -Version 1.0.1     # then add the changelog entries in readme.txt and CHANGELOG.md
git commit -am "chore: 1.0.1"; git tag v1.0.1; git push --follow-tags
```

`release.yml` builds the zip on the tag, checks the tag matches the plugin version, and attaches
the zip and `SHA256SUMS.txt` to the GitHub release.

## License

MIT.
