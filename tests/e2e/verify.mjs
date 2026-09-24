// End-to-end proof against a real WordPress in Docker (see docker-compose.yml):
//
//   1. waits for WordPress, completes the install wizard if it is showing, logs in,
//   2. uploads artifacts/deployseal-<version>.zip through Plugins → Add New → Upload Plugin
//      (replacing an earlier upload) and activates it,
//   3. configures it (fake site key, label "staging", admin toggle off), screenshots the page,
//   4. asserts the storefront <head> carries exactly one tag, byte for byte the contract's tag,
//      data-ds-installer="wordpress-plugin/<version>" and the environment and build attributes
//      included; that wp-admin has no tag while "Also load in the admin area" is off and exactly one
//      once it is on; and that wp-login.php never has it (checked with the admin toggle on),
//   5. build marker: default (WordPress + theme version), a git SHA file (+sha7), the
//      DEPLOYSEAL_BUILD constant (defined from a must-use plugin), each shown on the settings page
//      and emitted on the storefront,
//   6. installs WooCommerce (unless DS_SKIP_WOOCOMMERCE=1), checks WooCommerce lists the plugin as
//      compatible with HPOS and that the settings page shows the WooCommerce version,
//   7. starts a stub DeploySeal API on the host, points the Advanced "API base" at it through
//      host.docker.internal, presses "Send inventory now" and asserts the request shape
//      (POST /api/v1/sites/{key}/inventory, Bearer key, JSON body platform/platformVersion/
//      buildMarker/capturedAt/items in that order, items sorted and unique, the plugin itself,
//      WordPress, PHP, the theme and WooCommerce present), 201 then 200 on the page, and the
//      six-hourly WP-Cron event registered.
//
// Usage (Playwright is resolved from DS_E2E_NODE_MODULES when tests/e2e has no node_modules):
//   docker compose up -d
//   DS_E2E_NODE_MODULES=<node_modules with playwright> node tests/e2e/verify.mjs
// Env overrides:
//   DS_WP_URL         base URL                           (default http://localhost:8100)
//   DS_WP_CONTAINER   WordPress container, for docker exec (default deployseal-wp-wordpress-1; "" skips
//                     the SHA-file, constant, WooCommerce and cron scenarios)
//   DS_DB_CONTAINER   MariaDB container                   (default deployseal-wp-db-1)
//   DS_ADMIN_USER / DS_ADMIN_PASSWORD                     (created by the wizard if needed)
//   DS_ZIP            plugin zip                         (default artifacts/deployseal-<PLUGIN_VERSION>.zip)
//   DS_SCREENSHOT     output PNG                         (default artifacts/settings.png)
//   DS_STUB_PORT      host port of the stub API          (default 18100)
//   DS_STUB_HOST      how the container reaches the host (default host.docker.internal)
//   DS_SKIP_WOOCOMMERCE=1                                skip step 6

import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const modulesDir = process.env.DS_E2E_NODE_MODULES;
const require = createRequire(modulesDir ? path.join(path.resolve(modulesDir), 'x.js') : import.meta.url);
function loadPlaywright() {
  try { return require('playwright'); } catch {}
  const testPkg = require.resolve('@playwright/test/package.json');
  return createRequire(fs.realpathSync(testPkg))('playwright');
}
const { chromium } = loadPlaywright();

const PLUGIN_VERSION = '1.0.0';
const INSTALLER = `wordpress-plugin/${PLUGIN_VERSION}`;

const BASE = (process.env.DS_WP_URL || 'http://localhost:8100').replace(/\/$/, '');
const CONTAINER = process.env.DS_WP_CONTAINER !== undefined ? process.env.DS_WP_CONTAINER : 'deployseal-wp-wordpress-1';
const DB_CONTAINER = process.env.DS_DB_CONTAINER || 'deployseal-wp-db-1';
const ADMIN_USER = process.env.DS_ADMIN_USER || 'dsadmin';
const ADMIN_PASSWORD = process.env.DS_ADMIN_PASSWORD || 'Ds-e2e!Pass-2026#strong';
const ZIP = process.env.DS_ZIP || path.join(repoRoot, 'artifacts', `deployseal-${PLUGIN_VERSION}.zip`);
const SCREENSHOT = process.env.DS_SCREENSHOT || path.join(repoRoot, 'artifacts', 'settings.png');
const WITH_WOO = !process.env.DS_SKIP_WOOCOMMERCE && !!CONTAINER;

const SITE_KEY = 'ls_test0000000000000000000000000000000';
const API_KEY = 'ds_test_e2e0000000000000000000000000000';
const STUB_PORT = Number(process.env.DS_STUB_PORT || 18100);
const STUB_HOST = process.env.DS_STUB_HOST || 'host.docker.internal';
const STUB_BASE = `http://${STUB_HOST}:${STUB_PORT}`;
const LABEL = 'staging';
const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const SHA_FILE = 'wp-content/build-sha.txt';
const CONSTANT_BUILD = 'e2e-const-7f3a9c1';
const SETTINGS = BASE + '/wp-admin/options-general.php?page=deployseal';
const CLEAR_KEY = 'input[name="deployseal_settings[clear_api_key]"]';

// The contract tag as the plugin renders it: same attribute order as every DeploySeal install path.
const tag = (build) =>
  `<script src="https://cdn.deployseal.com/ds-widget.js" data-ds-site-key="${SITE_KEY}" data-ds-environment="${LABEL}"` +
  (build ? ` data-ds-build="${build}"` : '') + ` data-ds-installer="${INSTALLER}" async></script>`;

const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function assert(cond, msg) { if (!cond) throw new Error('ASSERTION FAILED: ' + msg); }
const dockerExec = (container, script, opts = {}) =>
  execFileSync('docker', ['exec', ...(opts.user ? ['-u', opts.user] : []), container, 'sh', '-c', script], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
const isActive = async (loc) => loc.evaluate((e) => e.classList.contains('active'));
const countTags = (html) => html.split('cdn.deployseal.com/ds-widget.js').length - 1;
const headOf = (html) => html.match(/<head[\s\S]*?<\/head>/i)?.[0] || '';

async function waitForServer(timeoutMs = 5 * 60 * 1000) {
  const start = Date.now();
  let last = '';
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(BASE + '/wp-login.php', { redirect: 'manual' });
      last = String(res.status);
      if (res.status < 500) { log(`WordPress responding (${last})`); return; }
    } catch (e) { last = e.message; }
    await sleep(3000);
  }
  throw new Error('WordPress did not answer: ' + last);
}

async function fetchHtml(context, url) {
  const res = await context.request.get(url, { maxRedirects: 5 });
  return { status: res.status(), url: res.url(), html: await res.text() };
}

// A stand-in for api.deployseal.com: records every request and answers like the real endpoint
// (201 for a new item set, 200 with the same id when the identical set is posted again).
function startStub() {
  const requests = [];
  const seen = new Map();
  const server = http.createServer((req, res) => {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => {
      let body = null;
      try { body = JSON.parse(raw); } catch {}
      requests.push({ method: req.method, url: req.url, headers: req.headers, raw, body });
      const items = body && Array.isArray(body.items) ? body.items : null;
      if (req.method !== 'POST' || !/^\/api\/v1\/sites\/[^/]+\/inventory$/.test(req.url) || !items) {
        res.writeHead(400, { 'content-type': 'application/problem+json' });
        res.end(JSON.stringify({ status: 400, title: 'Invalid inventory snapshot.', detail: 'stub: bad request', errors: { items: ['stub'] } }));
        return;
      }
      const fingerprint = JSON.stringify(items.map((i) => [i.systemName, i.name, i.version, i.enabled]));
      const existing = seen.get(fingerprint);
      const id = existing || `stub-${seen.size + 1}`;
      if (!existing) seen.set(fingerprint, id);
      res.writeHead(existing ? 200 : 201, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ id, capturedAt: body.capturedAt, itemCount: items.length }));
    });
  });
  return new Promise((resolve, reject) => {
    server.on('error', reject);
    server.listen(STUB_PORT, '0.0.0.0', () => resolve({ server, requests, close: () => new Promise((r) => server.close(r)) }));
  });
}

async function openFold(page, id) {
  await page.evaluate((elId) => { const el = document.getElementById(elId); if (el && !el.open) el.open = true; }, id);
}

// Loads the settings page, applies field changes, saves, and waits for the reload.
async function saveSettings(page, changes) {
  await page.goto(SETTINGS);
  await page.waitForSelector('#deployseal-site-key');
  await openFold(page, 'deployseal-advanced');
  await openFold(page, 'deployseal-platform-inventory');
  for (const [selector, value] of Object.entries(changes)) {
    if (selector === CLEAR_KEY) { if (await page.locator(CLEAR_KEY).count()) await page.setChecked(CLEAR_KEY, value); continue; }
    if (typeof value === 'boolean') await page.setChecked(selector, value);
    else if (selector === '#deployseal-build-source-select') await page.selectOption(selector, value);
    else await page.fill(selector, value);
  }
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#deployseal-save')]);
  // WordPress strips ?settings-updated=true from the address bar (wp_admin_canonical_url), so read the notice.
  assert(await page.locator('#setting-error-settings_updated').count() === 1, 'save did not show "Settings saved.": ' + page.url());
}

async function main() {
  assert(fs.existsSync(ZIP), `plugin zip not found: ${ZIP} (run build/build.ps1 first)`);
  fs.mkdirSync(path.dirname(SCREENSHOT), { recursive: true });
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(90_000);
  const summary = { base: BASE, zip: path.basename(ZIP), steps: [] };

  try {
    // 1. Install wizard + login ------------------------------------------------------------------
    await waitForServer();
    await page.goto(BASE + '/wp-admin/install.php');
    if (await page.locator('#language-continue').count()) {
      await page.selectOption('#language', { value: '' }).catch(() => {});
      await Promise.all([page.waitForNavigation(), page.click('#language-continue')]);
    }
    if (await page.locator('#weblog_title').count()) {
      log('install wizard showing; filling it');
      await page.fill('#weblog_title', 'DeploySeal E2E');
      await page.fill('#user_login', ADMIN_USER);
      await page.fill('#pass1', ADMIN_PASSWORD);
      // The strength meter runs asynchronously and may flash "weak" (and hide the confirm box) while it
      // loads; tick "Confirm use of weak password" through the DOM so the submit button is never blocked.
      await page.waitForTimeout(1000);
      await page.evaluate(() => {
        const box = document.querySelector('.pw-checkbox');
        if (box && !box.checked) { box.checked = true; box.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      await page.fill('#admin_email', 'admin@deployseal.test');
      await Promise.all([page.waitForNavigation(), page.click('#submit')]);
      assert(await page.locator('text=Success!').count(), 'install wizard did not report success');
      summary.steps.push('install wizard completed');
    } else {
      summary.steps.push('WordPress was already installed');
    }
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    assert(/\/wp-admin\//.test(page.url()), 'login failed: ' + page.url());
    const wpVersion = ((await fetchHtml(context, BASE + '/')).html.match(/<meta name="generator" content="WordPress ([0-9.]+)"/) || [])[1];
    assert(wpVersion, 'could not read the WordPress version from the generator meta tag');
    log('logged in; WordPress', wpVersion);

    // 2. Upload the zip, activate ------------------------------------------------------------------
    await page.goto(BASE + '/wp-admin/plugin-install.php?tab=upload');
    await page.setInputFiles('#pluginzip', ZIP);
    await Promise.all([page.waitForNavigation(), page.click('#install-plugin-submit')]);
    const overwrite = page.locator('a.update-from-upload-overwrite');
    if (await overwrite.count()) {
      await Promise.all([page.waitForNavigation(), overwrite.click()]);
      summary.steps.push('zip uploaded (replaced the earlier upload)');
    } else {
      summary.steps.push('zip uploaded (fresh install)');
    }
    const body = await page.locator('#wpbody-content').innerText();
    assert(/Plugin (installed|updated) successfully/i.test(body), 'upload did not succeed: ' + body.slice(0, 400));
    await page.goto(BASE + '/wp-admin/plugins.php');
    const row = page.locator('tr[data-plugin="deployseal/deployseal.php"]');
    assert(await row.count() === 1, 'the plugin is not listed as deployseal/deployseal.php (zip root must be the deployseal/ folder)');
    if (!(await isActive(row))) {
      await Promise.all([page.waitForNavigation(), row.locator('a[href*="action=activate"]').click()]);
    }
    assert(await isActive(page.locator('tr[data-plugin="deployseal/deployseal.php"]')), 'plugin did not activate');
    assert(await page.locator('tr[data-plugin="deployseal/deployseal.php"] a[href*="page=deployseal"]').count() === 1, 'Settings action link missing');
    summary.steps.push('plugin active');

    // Clean slate for re-runs.
    if (CONTAINER) {
      dockerExec(CONTAINER, `rm -f /var/www/html/${SHA_FILE} /var/www/html/wp-content/mu-plugins/ds-e2e-build.php`);
    }

    // 3. Configure ---------------------------------------------------------------------------------
    await saveSettings(page, {
      [CLEAR_KEY]: true,
      '#deployseal-site-key': SITE_KEY,
      '#deployseal-enabled': true,
      '#deployseal-environment-label': LABEL,
      '#deployseal-build-source-select': 'auto',
      '#deployseal-git-sha-file': '',
      '#deployseal-manual-build': '',
      '#deployseal-load-in-admin': false,
      '#deployseal-api-base': 'https://api.deployseal.com',
      '#deployseal-send-inventory': false,
    });
    const shownBuild = (await page.locator('#deployseal-build').innerText()).trim();
    const shownOrigins = await page.locator('code.deployseal-origin').allTextContents();
    const preview = (await page.locator('#deployseal-snippet').innerText()).trim();
    const versionsRow = await page.locator('#deployseal-facts').innerText();
    log('settings page shows build', JSON.stringify(shownBuild), 'origins', JSON.stringify(shownOrigins));
    assert(new RegExp(`^${wpVersion.replace(/\./g, '\\.')}\\+[a-z0-9._-]+-[0-9][0-9A-Za-z.+-]*$`).test(shownBuild), `default build marker should be <wp>+<theme>-<version>, got ${shownBuild}`);
    assert(shownOrigins.includes(new URL(BASE).origin), `origins ${JSON.stringify(shownOrigins)} do not include ${new URL(BASE).origin}`);
    assert(preview === tag(shownBuild), `settings preview mismatch:\n  got  ${preview}\n  want ${tag(shownBuild)}`);
    assert((await page.locator('#deployseal-advanced').evaluate((el) => el.open)) === true, 'Advanced must open itself when a value in it is set (label "staging")');
    assert(/Versions/.test(versionsRow), 'versions row missing');
    const PLATFORM_BUILD = shownBuild;
    summary.configurePreview = preview;
    summary.origins = shownOrigins;

    // 4a. Storefront (anonymous) --------------------------------------------------------------------
    const anon = await browser.newContext();
    const home = await fetchHtml(anon, BASE + '/');
    assert(home.status === 200, 'home returned ' + home.status);
    const head = headOf(home.html);
    assert(head.includes(tag(PLATFORM_BUILD)), `storefront <head> does not contain the exact tag.\n  want ${tag(PLATFORM_BUILD)}\n  saw  ${(home.html.match(/<script[^>]*ds-widget[^>]*>/i) || ['<none>'])[0]}`);
    assert(countTags(home.html) === 1, `the tag must appear exactly once on the page, saw ${countTags(home.html)}`);
    assert(!/ds-widget\.js\?ver=/.test(home.html) && !/id="deployseal-widget-js"/.test(home.html), 'WordPress\'s own tag leaked through');
    const shop = await fetchHtml(anon, BASE + '/?p=1');
    assert(countTags(shop.html) === 1 && headOf(shop.html).includes(tag(PLATFORM_BUILD)), 'a single post must carry the tag too');
    summary.storefrontTag = tag(PLATFORM_BUILD);
    log('storefront emitted:', summary.storefrontTag);

    // 4b. wp-admin off by default, 4c. login page never ------------------------------------------------
    const admin1 = await fetchHtml(context, BASE + '/wp-admin/');
    assert(admin1.status === 200 && /wp-admin/.test(admin1.url), 'could not load wp-admin');
    assert(countTags(admin1.html) === 0, 'wp-admin must not contain the tag by default');
    const login1 = await fetchHtml(anon, BASE + '/wp-login.php');
    assert(countTags(login1.html) === 0, 'wp-login.php must never contain the tag');

    await saveSettings(page, { '#deployseal-load-in-admin': true });
    const admin2 = await fetchHtml(context, BASE + '/wp-admin/');
    assert(countTags(admin2.html) === 1 && headOf(admin2.html).includes(tag(PLATFORM_BUILD)), `with the admin toggle on, wp-admin must carry the exact tag once (saw ${countTags(admin2.html)})`);
    const login2 = await fetchHtml(anon, BASE + '/wp-login.php');
    assert(countTags(login2.html) === 0, 'wp-login.php must not contain the tag even with the admin toggle on');
    await saveSettings(page, { '#deployseal-load-in-admin': false });
    const admin3 = await fetchHtml(context, BASE + '/wp-admin/');
    assert(countTags(admin3.html) === 0, 'wp-admin must drop the tag again once the toggle is off');
    summary.steps.push('storefront <head> has the exact tag once; wp-admin none by default, once with the toggle on; wp-login.php never');

    // Disabled → nothing rendered.
    await saveSettings(page, { '#deployseal-enabled': false });
    assert(countTags((await fetchHtml(anon, BASE + '/')).html) === 0, 'disabled plugin must render nothing');
    await saveSettings(page, { '#deployseal-enabled': true });
    summary.steps.push('Enabled off renders nothing');

    // 5. Build marker sources --------------------------------------------------------------------------
    if (CONTAINER) {
      dockerExec(CONTAINER, `printf '%s\\n' '${SHA}' > /var/www/html/${SHA_FILE}`);
      await saveSettings(page, { '#deployseal-git-sha-file': SHA_FILE });
      const shaBuild = (await page.locator('#deployseal-build').innerText()).trim();
      assert(shaBuild === `${PLATFORM_BUILD}+${SHA.slice(0, 7)}`, `with SHA file: got ${shaBuild}`);
      assert(headOf((await fetchHtml(anon, BASE + '/')).html).includes(tag(shaBuild)), 'storefront did not emit the +sha marker');
      summary.storefrontTagWithSha = tag(shaBuild);

      dockerExec(CONTAINER, `mkdir -p /var/www/html/wp-content/mu-plugins && printf '%s\\n' "<?php define( 'DEPLOYSEAL_BUILD', '${CONSTANT_BUILD}' );" > /var/www/html/wp-content/mu-plugins/ds-e2e-build.php`);
      await page.goto(SETTINGS);
      const constBuild = (await page.locator('#deployseal-build').innerText()).trim();
      assert(constBuild === CONSTANT_BUILD, `with DEPLOYSEAL_BUILD defined: got ${constBuild}`);
      assert(headOf((await fetchHtml(anon, BASE + '/')).html).includes(tag(CONSTANT_BUILD)), 'storefront did not emit the constant marker');
      dockerExec(CONTAINER, `rm -f /var/www/html/wp-content/mu-plugins/ds-e2e-build.php /var/www/html/${SHA_FILE}`);
      await saveSettings(page, { '#deployseal-git-sha-file': '' });
      assert((await page.locator('#deployseal-build').innerText()).trim() === PLATFORM_BUILD, 'marker did not return to the default');
      summary.steps.push(`build marker: default ${PLATFORM_BUILD}; SHA file → +${SHA.slice(0, 7)}; DEPLOYSEAL_BUILD constant wins`);
    }

    await saveSettings(page, { '#deployseal-build-source-select': 'manual', '#deployseal-manual-build': 'REPLACE-WITH-BUILD-MARKER' });
    assert(!(await fetchHtml(anon, BASE + '/')).html.includes('data-ds-build'), 'the placeholder must never be emitted');
    await saveSettings(page, { '#deployseal-build-source-select': 'auto', '#deployseal-manual-build': '' });
    summary.steps.push('placeholder REPLACE-WITH-BUILD-MARKER is never emitted');

    // 6. WooCommerce ------------------------------------------------------------------------------------
    let wooVersion = null;
    if (WITH_WOO) {
      const present = dockerExec(CONTAINER, 'test -f /var/www/html/wp-content/plugins/woocommerce/woocommerce.php && echo yes || echo no').trim();
      if (present !== 'yes') {
        log('downloading WooCommerce into the container');
        dockerExec(CONTAINER, `cd /tmp && curl -fsSL -o wc.zip https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip && php -r '$z=new ZipArchive; if ($z->open("/tmp/wc.zip")!==true) exit(1); $z->extractTo("/var/www/html/wp-content/plugins"); $z->close();' && chown -R www-data:www-data /var/www/html/wp-content/plugins/woocommerce && rm wc.zip`);
      }
      await page.goto(BASE + '/wp-admin/plugins.php');
      const wooRow = page.locator('tr[data-plugin="woocommerce/woocommerce.php"]');
      assert(await wooRow.count() === 1, 'WooCommerce not listed');
      if (!(await isActive(wooRow))) {
        await Promise.all([page.waitForNavigation(), wooRow.locator('a[href*="action=activate"]').click()]);
      }
      const compat = dockerExec(CONTAINER, `cd /var/www/html && php -r '$_SERVER["HTTP_HOST"]="localhost:8100"; require "wp-load.php"; $c = wc_get_container()->get(Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::class); echo json_encode(array("wc" => WC_VERSION, "hpos" => $c->get_compatible_plugins_for_feature("custom_order_tables")));' 2>/dev/null`);
      const parsed = JSON.parse(compat.slice(compat.indexOf('{')));
      wooVersion = parsed.wc;
      assert((parsed.hpos.compatible || []).includes('deployseal/deployseal.php'), 'WooCommerce does not list the plugin as HPOS-compatible: ' + compat);
      assert(!(parsed.hpos.incompatible || []).includes('deployseal/deployseal.php'), 'WooCommerce lists the plugin as HPOS-incompatible');
      await page.goto(SETTINGS);
      assert((await page.locator('#deployseal-facts').innerText()).includes(`WooCommerce ${wooVersion}`), 'settings page does not show the WooCommerce version');
      const shopPage = await fetchHtml(anon, BASE + '/?post_type=product');
      assert(countTags(shopPage.html) === 1, 'WooCommerce shop page must carry the tag once');
      summary.steps.push(`WooCommerce ${wooVersion} active: HPOS-compatible declaration accepted, version shown, shop page tagged once`);
    }

    // 7. Inventory against a stub API on the host --------------------------------------------------------
    const stub = await startStub();
    try {
      log(`stub DeploySeal API on ${STUB_BASE}`);
      await page.goto(SETTINGS);
      await openFold(page, 'deployseal-platform-inventory');
      assert(await page.locator('#deployseal-inventory-notready').count() === 1, 'without an API key the inventory card must say it is not ready');
      assert(await page.locator('#deployseal-send-now').isDisabled(), 'without an API key "Send inventory now" must be disabled');
      await saveSettings(page, { '#deployseal-api-key': API_KEY, '#deployseal-api-base': STUB_BASE, '#deployseal-send-inventory': true });
      assert(await page.locator('#deployseal-platform-inventory').evaluate((el) => el.open), 'Platform inventory must open itself once a key is stored');
      assert((await page.inputValue('#deployseal-api-key')) === '', 'the API key box must not echo the stored key');
      assert(!(await page.content()).includes(API_KEY), 'the stored API key must not appear anywhere in the page');
      assert(await page.locator('#deployseal-apikey-stored').count() === 1, 'the page must say a key is stored');
      const endpoint = (await page.locator('#deployseal-inventory-endpoint').innerText()).trim();
      assert(endpoint === `${STUB_BASE}/api/v1/sites/${SITE_KEY}/inventory`, 'endpoint shown: ' + endpoint);
      const shownCount = Number((await page.locator('#deployseal-inventory-count').innerText()).trim());
      assert(!(await page.locator('#deployseal-send-now').isDisabled()), '"Send inventory now" must be enabled');
      await page.screenshot({ path: SCREENSHOT, fullPage: true });

      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#deployseal-send-now')]);
      const notice1 = (await page.locator('#deployseal-inventory-result').innerText().catch(() => '')).trim();
      assert(stub.requests.length === 1, `stub expected one request, got ${stub.requests.length} (page said: ${notice1})`);
      const r = stub.requests[0];
      assert(r.method === 'POST', 'method: ' + r.method);
      assert(r.url === `/api/v1/sites/${SITE_KEY}/inventory`, 'path: ' + r.url);
      assert(r.headers.authorization === `Bearer ${API_KEY}`, 'authorization: ' + r.headers.authorization);
      assert(/^application\/json/.test(r.headers['content-type'] || ''), 'content-type: ' + r.headers['content-type']);
      assert(r.body && Object.keys(r.body).join(',') === 'platform,platformVersion,buildMarker,capturedAt,items', 'body keys: ' + (r.body && Object.keys(r.body)));
      assert(r.body.platform === 'wordpress', 'platform: ' + r.body.platform);
      assert(r.body.platformVersion === wpVersion, `platformVersion ${r.body.platformVersion} (want ${wpVersion})`);
      assert(r.body.buildMarker === PLATFORM_BUILD, `buildMarker ${r.body.buildMarker} (want ${PLATFORM_BUILD})`);
      const age = Date.now() - Date.parse(r.body.capturedAt);
      assert(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(r.body.capturedAt) && age > -60_000 && age < 10 * 60_000, 'capturedAt: ' + r.body.capturedAt);
      assert(Array.isArray(r.body.items) && r.body.items.length === shownCount, `items sent ${r.body.items.length}, shown ${shownCount}`);
      for (const item of r.body.items) {
        assert(Object.keys(item).join(',') === 'systemName,name,version,enabled', 'item keys: ' + Object.keys(item));
        assert(typeof item.systemName === 'string' && item.systemName.length > 0 && item.systemName.length <= 128, 'systemName: ' + item.systemName);
        assert(typeof item.name === 'string' && item.name.length > 0 && item.name.length <= 200, 'name: ' + item.name);
        assert(typeof item.version === 'string' && item.version.length > 0 && item.version.length <= 32, 'version: ' + item.version);
        assert(typeof item.enabled === 'boolean', 'enabled: ' + item.enabled);
      }
      const names = r.body.items.map((i) => i.systemName);
      const sorted = names.slice().sort((a, b) => (Buffer.from(a) < Buffer.from(b) ? -1 : Buffer.from(a) > Buffer.from(b) ? 1 : 0));
      assert(sorted.join('\n') === names.join('\n'), 'items must be sorted by systemName (byte order)');
      assert(new Set(names).size === names.length, 'systemNames must be unique');
      const find = (n) => r.body.items.find((i) => i.systemName === n);
      assert(find('plugin:deployseal/deployseal.php')?.enabled === true && find('plugin:deployseal/deployseal.php').version === PLUGIN_VERSION, 'the plugin must report itself: ' + JSON.stringify(find('plugin:deployseal/deployseal.php')));
      assert(find('core:wordpress')?.version === wpVersion, 'core:wordpress item');
      assert(/^\d+\.\d+\.\d+/.test(find('runtime:php')?.version || ''), 'runtime:php item');
      assert(r.body.items.some((i) => i.systemName.startsWith('theme:') && i.enabled), 'the active theme must be reported');
      assert(r.body.items.some((i) => i.systemName.startsWith('theme:') && !i.enabled), 'inactive installed themes are reported as enabled:false');
      if (WITH_WOO) assert(find('plugin:woocommerce/woocommerce.php')?.enabled === true && find('plugin:woocommerce/woocommerce.php').version === wooVersion, 'WooCommerce must be reported with its version');
      assert(/HTTP 201/.test(notice1) && new RegExp(`${r.body.items.length} items`).test(notice1), 'the page must show the 201 and the count: ' + notice1);

      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#deployseal-send-now')]);
      const notice2 = (await page.locator('#deployseal-inventory-result').innerText()).trim();
      assert(stub.requests.length === 2, 'second send must post once more');
      assert(/HTTP 200/.test(notice2) && /already on record/.test(notice2), 'the page must show the 200 as already on record: ' + notice2);

      if (CONTAINER) {
        const cron = execFileSync('docker', ['exec', DB_CONTAINER, 'mariadb', '-uwordpress', '-pwordpress', 'wordpress', '-N', '-e', "SELECT option_value FROM wp_options WHERE option_name='cron'"], { encoding: 'utf8' });
        assert(/deployseal_send_inventory/.test(cron) && /deployseal_six_hourly/.test(cron) && /i:21600/.test(cron), 'six-hourly WP-Cron event not registered');
      }

      summary.steps.push(`inventory: ${r.body.items.length} items posted to ${STUB_BASE}${r.url} with the bearer key (201, then 200 on resend); WP-Cron six-hourly event registered`);
      summary.inventoryRequest = { url: r.url, platform: r.body.platform, platformVersion: r.body.platformVersion, buildMarker: r.body.buildMarker, capturedAt: r.body.capturedAt, itemCount: r.body.items.length, first: r.body.items[0] };

      // Leave the site in a tidy state: default API base, key removed, schedule off.
      await saveSettings(page, { [CLEAR_KEY]: true, '#deployseal-api-base': 'https://api.deployseal.com', '#deployseal-send-inventory': false });
    } finally {
      await stub.close();
    }
    await anon.close();

    console.log('\nVERIFIED\n' + JSON.stringify(summary, null, 2));
  } catch (e) {
    try { await page.screenshot({ path: path.join(path.dirname(SCREENSHOT), 'failure.png'), fullPage: true }); } catch {}
    console.error('\nFAILED:', e.message, '\nat', page.url());
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main();
