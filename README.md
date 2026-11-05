# Afterware Connect

Inventory, risk report and signed in-place updates for abandoned WordPress plugins,
maintained by [Afterware](https://afterware.io). See `readme.txt` for the WordPress.org
listing, including the exact list of data the plugin sends.

## What it does

- Reports an inventory of your plugins and themes to Afterware once a day (or on demand
  from "Scan now"): see `readme.txt`'s "What does Afterware Connect send?" for the exact
  inventory fields.
- Shows a risk report: which installed plugins are abandoned or unmaintained upstream,
  and which of those Afterware maintains a signed replacement build for.
- Verifies every manifest and package independently with Ed25519 signatures and
  SHA-256 hashes before installing anything (`docs/security.md`); a failed verification
  never installs and always leaves an activity entry and opens one support ticket.
- Lets you switch a plugin to the Afterware build in place, and revert to the backed-up
  upstream copy at any time from the plugin's own screen.
- Opens support tickets, with an explicit opt-in before any debug-log excerpt is attached.
- Reports the first package installation verified by Connect through background delivery:
  schema 1, plugin slug, Afterware version and package SHA-256, authenticated by the site
  key. One pending receipt has up to six scheduled attempts within seven days; disconnect
  and uninstall clear it. The installed build is rechecked before delivery. The server
  keeps one first receipt per site; this is not proof of compatibility or payment.

## Install

1. Download the ZIP from the [Afterware Connect installation guide](https://afterware.io/docs/install).
   In WordPress, open **Plugins → Add New → Upload Plugin** and select the ZIP.
2. Activate it through the "Plugins" screen. Per-site activation only: network-wide
   activation is refused with a notice explaining why.
3. Visit "Afterware" in the admin menu. The first visit connects the site (see Connect
   below) and runs the first scan.

## Link to your dashboard account

After registration, open **Afterware → Settings → Connect to your Afterware account**.
Choose **Get connection code**, copy the code and open the dashboard. Sign in, select the
workspace that should manage this site and enter the code. Your site key stays in WordPress.
The code expires after 15 minutes; a replacement invalidates the previous unused code.
Only the requesting administrator can see the saved code, and plugin-management permission
is required. If copying is unavailable on plain HTTP, select and copy the displayed text.

Issuing a code does not confirm association or payment. Complete the dashboard step and
select a subscription seat separately. If the site already belongs to a workspace, its
owner must transfer it in the dashboard. A failed replacement hides the previous local code
because the server may already have invalidated it. No code or site key enters activity logs.

## Connect

Activating the plugin registers the site with Afterware on the next admin request: it
sends the site URL and receives back a site key, stored locally, that authenticates
later site API requests. Nothing is scanned or reported before that registration succeeds,
and a registration failure leaves a notice explaining what to try. From the Settings
screen you can:

- review the exact inventory request the plugin would send before it sends it,
- disconnect at any time, which deletes the site key locally and stops authenticated
  reporting and pending installation-receipt delivery,
- opt in separately, per ticket, before a debug-log excerpt is attached.

Public signing-ring refreshes can continue while disconnected when the build has a trusted
key. They use the dedicated public-file transport and send no site key or inventory.

## Switching safely

Connect refuses an offered build older than the plugin version currently installed. The
plugin screen explains the mismatch and keeps your current files and activation unchanged.
The overview excludes that offer from its switchable count, even if an older scan named it.

Test on staging and keep a full site backup. A maintained build can run database upgrades;
Connect's revert restores saved plugin files, not those database changes.

Runtime requirements: a switch and an Afterware package download require explicit WordPress
and PHP minimums from the verified manifest that this site's numeric runtime versions meet.
Missing or unusual requirements need support review. The plugin screen explains a refusal;
a stale report cannot reopen a locally refused offer. These checks do not establish whole-site
compatibility. Test on staging and keep a full site backup. Local file revert remains a
separate recovery path and does not undo database upgrades.

## Requirements

- PHP 7.4+ (tested to 8.3), WordPress 5.8+.
- No PHP or Composer on your host is needed to develop: everything PHP runs inside
  `wp-env` containers.
- Node 24 and pnpm 10 for the dev toolchain (`@wordpress/env`, Playwright, fixture
  generation). Zero JavaScript build step ships in the plugin itself.

## Update access and outages

A site without current paid coverage can receive HTTP 402 from the updates service.
Afterware Connect then stops offering or downloading new builds and points you to your
account's coverage and billing. HTTP 401/403 similarly pause updates and ask you to check
the connection. Installed plugins, their activation and local revert backups are unchanged.
A risk report cannot override a known access denial.

During ordinary timeouts, rate limits or service outages, the client may use a previously
signed manifest for up to 14 days, verifying its signature again on every read. The normal
refresh interval is six hours, so coverage changes take effect when the next update check
observes them. Once access is explicitly denied, an outage cannot restore the old cache.
A successful response restores delivery only through the normal signature/hash checks.
Access denials do not create automatic security-failure tickets.

## Development

```bash
pnpm install        # JS dev toolchain
pnpm env:start       # boots wp-env (pulls WordPress + PHP images on first run)
pnpm env:install     # composer install inside the tests-cli container
pnpm test:php        # PHPUnit against the WordPress test library
pnpm lint:php        # PHPCS, WordPress coding standards
pnpm stan            # PHPStan level 6
pnpm test:e2e:install # one-off: the Chromium build Playwright pins
pnpm test:e2e        # Playwright against the running dev site on :8888
pnpm env:stop        # stop wp-env
```

### End-to-end tests

`tests/e2e` drives the real wp-admin screens in a browser: connecting, scanning,
switching to an Afterware build and reverting it, a manifest that does not
verify, and an axe sweep of every screen in all nine admin colour schemes. They
run against the wp-env **dev** site (`:8888`), which must already be up —
Playwright does not start it, because it owns a database and an installed copy of
the demo plugin that a per-run server would throw away.

Every spec starts from `resetSite()`, which deletes the plugin's options and
transients and puts the upstream demo plugin back, so the suite can be run
against a site somebody has been poking around in. It mutates one site, so
`workers: 1` and no retries.

What answers `AFTERWARE_API_BASE` is `tests/mu-plugins/afterware-stub.php`: a
mu-plugin serving the committed, pre-signed fixtures over the same routes the
platform exposes, with `?mode=` control routes for a tampered manifest and an
API that is down. It refuses to register its routes outside a `local`
environment. Screenshots from the colour-scheme sweep land in
`tests/e2e/screenshots/` (gitignored).

### Test fixtures

`pnpm fixtures` and `pnpm fixtures:check` delegate to the sibling platform's
`packages/signer/test/connect-fixtures-cli.ts`. Install the platform dependencies first;
set `AFTERWARE_PLATFORM_ROOT` if that checkout is not `../afterware`. Missing prerequisites
fail with instructions and do not change client fixtures. No code in this repository signs.

The three `tests/fixtures/test-keypair*.json` files retain their historical names but hold
**public key fields only**. The existing private TEST keypairs live exclusively under the
platform's `packages/signer/test/fixtures/connect/`; missing keys are never regenerated
automatically. They are committed test data and must never be used in production.

The signer writes the dev key ring, signed manifests and key-ring cases, API responses,
and the ignored test package ZIP. Check mode compares every tracked output without
rewriting it and creates only the missing ignored ZIP. PHP tests verify signatures using
public fixtures without needing the signer checkout. The independent workflow ZIP hash
test confirms that the package still matches the signed manifest.

The ZIP uses fixed raw timestamps and bytewise entry order. Its exact adm-zip development
version is pinned in the signer package. Existing signed fixture and package bytes were
preserved by the signing-boundary migration.

The signer generator also owns where the stub answers. `AFTERWARE_API_BASE` in
`.wp-env.json` and the `package_url` signed into every manifest are written from
one constant there, because the client refuses a package that is not the one the
signed manifest names. The host is `wordpress`, the docker-compose service name:
every container on the instance's network resolves it, `localhost` does not.
Update checks, cron events and `wp afterware …` run in the `cli` container, where
`http://localhost` is the CLI container and nothing is listening.

The dev key ring feeds three places, and `pnpm fixtures` writes all three, so never
hand-edit them:

- `tests/fixtures/dev-key-ring.json`, which `tests/phpunit/bootstrap.php` reads to
  define `AFTERWARE_DEV_KEY_RING` for PHPUnit.
- `.wp-env.json`'s `config.AFTERWARE_DEV_KEY_RING`, for the browsable site and
  Playwright. wp-env wraps string config values in double quotes and pipes them
  through `bash -c`, so the JSON's own quotes must be **double** escaped there
  (`\\\"` in the file, i.e. a literal `\"` in the string); a plain `\"` arrives with
  its quotes stripped and `json_decode()` returns null. Config changes only take
  effect after `pnpm env:stop && pnpm env:start`.
- The key ring the plugin actually trusts in production is pinned in
  `Afterware\Key_Ring::PRODUCTION`, which the dev ring never touches.

### mu-plugins

`.wp-env.json` maps the whole `wp-content/mu-plugins` directory to `tests/mu-plugins`,
which holds the stub API mu-plugin and its `data/` payloads. Map the directory, not
individual files: a single-file mapping makes Docker create a root-owned 0-byte file
in the wp-env instance directory that `wp-env start` then cannot overwrite, so every
later `.wp-env.json` edit fails with `EACCES`.

The plugin directory is mounted into the container at
`wp-content/plugins/afterware` (see `.wp-env.json`), so all `wp-env run` commands use
`--env-cwd=wp-content/plugins/afterware` regardless of this repo's directory name.

### Why we patch wp-env

`patches/@wordpress__env.patch` (applied automatically by `pnpm install` via
`pnpm.patchedDependencies` in `pnpm-workspace.yaml`) changes one line in
`@wordpress/env`'s generated `WordPress.Dockerfile`/`Tests-WordPress.Dockerfile`: the
`RUN apt-get -qy install git` step. On PHP 7.4 images (Debian bullseye) this
intermittently 404s fetching `git` from `deb.debian.org/debian-security` — the
`Packages` index lists a version the pool no longer serves, while the same-version
`git-man` package fetches fine, and a slightly older `git` build from the plain
`bullseye/main` (non-security) pool is present. The patch retries the install once
with the `bullseye-security` line removed from `/etc/apt/sources.list` if the first
attempt fails, so `wp-env start` doesn't get stuck on a transient upstream mirror gap.
If `@wordpress/env` ships an equivalent fix upstream, this patch can be dropped.

## Architecture

Namespace `Afterware\`, autoloaded by a tiny loader in `includes/autoload.php` (no
Composer autoload at runtime): `Afterware\Foo_Bar` -> `includes/class-foo-bar.php`,
`Afterware\Admin\Foo` -> `admin/class-foo.php`, `Afterware\CLI\Foo` ->
`includes/cli/class-foo.php`. See `AGENTS.md` for the full class map and the rules
that apply to every change.

## License

GPLv2 or later. See `LICENSE`.
