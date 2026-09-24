=== DeploySeal ===
Contributors: deployseal
Tags: testing, uat, qa, release, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Test releases on your real site with your team and stakeholders, and prove what was tested: environment, build and installed plugins.

== Description ==

= Test releases on your real site, and prove what was tested. =

DeploySeal lets your team and stakeholders review a WordPress or WooCommerce site before it goes live: testers follow a checklist on the actual pages, pin issues where they see them (screenshot, session replay and browser details captured automatically), developers fix and send them back for verification, and the people who sign off do so on a record that cannot be quietly changed afterwards.

This plugin is the WordPress install path for that widget. It does what a pasted script tag cannot:

* **A build marker that is true on every deployment.** The plugin derives it from your WordPress version and active theme version and, when available, the git commit of the deploy (a SHA file or a `DEPLOYSEAL_BUILD` constant in wp-config.php), so the readiness report can confirm that the build testers used is the build you shipped. No theme file to remember to edit.
* **One install per site, multisite included.** Every site keeps its own environment key and label, with the exact origins to register shown next to the field. Network activation is supported; settings stay per site.
* **Platform inventory as evidence (optional).** With a DeploySeal API key the plugin reports which plugins and themes, at which versions, were installed when a release was tested — WooCommerce included — and the report shows what changed since the last signed release. Useful for plugin updates and WooCommerce upgrade projects.
* **Survives theme updates.** The tag is enqueued by the plugin, not written into a theme, and never appears in wp-admin unless you choose that, and never on the login page.

**Setup takes two fields.** Create an environment for the site in DeploySeal, paste its public key into Settings → DeploySeal, tick Enabled, save. The page then shows exactly what the site will send (origins to register, declared environment label, the build marker, the rendered tag) so you can confirm it matches. Everything else (environment label, build marker source, git SHA file, inventory) lives under Advanced and Platform inventory with sensible defaults.

**What it sends.** Only the widget tag and, if you enable it, the platform inventory. Visitor and customer data is never touched; the widget loads for everyone but only activates for invited testers on registered origins.

Requires a DeploySeal account. DeploySeal is currently in private pilot: [request access at deployseal.com](https://deployseal.com/request-access?from=wordpress-plugin). The plugin is open source under MIT; issues and pull requests are welcome on [GitHub](https://github.com/deployseal/wordpress-plugin). Documentation: [deployseal.com/docs/wordpress](https://deployseal.com/docs/wordpress).

= External services =

This plugin connects to DeploySeal, a hosted go-live sign-off service operated at deployseal.com.

* **The widget loader.** When the plugin is enabled with a site key, every public page includes `<script src="https://cdn.deployseal.com/ds-widget.js" async>` with the site key, the declared environment label, the build marker and the plugin version as `data-ds-*` attributes. The visitor's browser downloads the widget from cdn.deployseal.com and sends a heartbeat (site key, page URL with sensitive query parameters removed, page title, environment label, build marker, widget version, installer) to api.deployseal.com on each page load. For invited testers only, the widget also sends the issues, screenshots and session replays they capture.
* **The platform inventory (optional, off until you add an API key).** From your server, never from the browser, the plugin posts the list of installed plugins and themes (name, version, active or not), the WordPress and PHP versions and the build marker to `https://api.deployseal.com/api/v1/sites/{site key}/inventory`, when you press "Send inventory now" and, if you turn on "Send inventory on a schedule", every 6 hours.

DeploySeal [terms of service](https://deployseal.com/terms) and [privacy policy](https://deployseal.com/privacy).

== Installation ==

1. Install through Plugins → Add New (search for "DeploySeal"), or upload `deployseal-1.0.0.zip` from the [GitHub releases](https://github.com/deployseal/wordpress-plugin/releases) through Plugins → Add New → Upload Plugin, then **Activate**.
2. In DeploySeal, open your site and create an environment for this WordPress site (for example *Staging* or *Production*). Register the origin(s) shown on Settings → DeploySeal under "What this site will send".
3. Copy the environment's public key (it starts with `ls_`) into **Site key**, tick **Enabled**, **Save**.
4. Open the site once; the environment shows as *Live* in DeploySeal. If you had pasted a DeploySeal snippet into the theme or a header plugin, remove it: DeploySeal warns when two install paths are live on one environment.

On a multisite network, network-activate the plugin or activate it per site; each site then gets its own Settings → DeploySeal page and its own environment key.

== Frequently Asked Questions ==

= Does it slow my site? =

No measurable amount. The plugin adds one `async` script tag to `<head>`: the browser downloads the 1 KB loader in parallel without blocking rendering, and the rest of the widget only loads after that. For visitors who are not invited testers the widget stays dormant. On the server, the plugin reads one option per request (and your SHA file, if you configured one). Nothing is added to wp-admin unless you turn that on.

= What does it send? =

The browser sends a heartbeat per page load (site key, scrubbed page URL, page title, environment label, build marker, widget version and installer) so DeploySeal can show the environment as Live. Invited testers also send the issues they pin, with screenshot and session replay. Only if you add an API key does the server send the platform inventory: installed plugins and themes with their versions, the WordPress and PHP versions and the build marker. No settings, users, customers or orders ever leave the site. See "External services" above.

= Does it work with WooCommerce? =

Yes. There is nothing WooCommerce-specific to configure: the tag loads on shop, cart and checkout pages like any other page, the plugin declares compatibility with High-Performance Order Storage and the cart and checkout blocks, and the platform inventory records the WooCommerce version and its extensions.

= Which build marker will it send? =

By default: the `DEPLOYSEAL_BUILD` constant when wp-config.php defines one; otherwise the WordPress version plus the active theme and its version (`7.1.2+twentytwentyfive-1.3`), plus the short git SHA when a SHA file your deploy writes can be read (`7.1.2+twentytwentyfive-1.3+a1b2c3d`). The settings page prints the exact value; copy it verbatim into the campaign's release identifier.

= Why does DeploySeal say the environment is Not seen? =

Most often the origin: DeploySeal matches `scheme://host[:port]` exactly, so register every origin the settings page lists. Also check that Enabled is ticked, that the key is the one for this environment, and that a caching plugin has been purged since you enabled the plugin.

= Is the site key a secret? =

No. It only works from the environment's registered origins, which is why it can sit in your HTML. The API key used for the platform inventory is a secret: the settings page never shows it again once saved.

== Changelog ==

= 1.0.0 =
* First release: the contract tag (site key, environment label, build marker, `data-ds-installer="wordpress-plugin/1.0.0"`) in `<head>` on the public site; optional in wp-admin; never on the login page.
* Build marker from WordPress + theme version, a git SHA file, the `DEPLOYSEAL_BUILD` constant or a manual value.
* Platform inventory: "Send inventory now" and a six-hourly WP-Cron send.
* Multisite (per-site settings, network activation) and WooCommerce HPOS compatibility.
