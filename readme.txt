=== Afterware Connect ===
Contributors: afterware
Tags: security, updates, plugins, maintenance, inventory
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inventory, risk report and signed in-place updates for abandoned WordPress plugins,
maintained by Afterware.

== Description ==

Afterware Connect reports your site's plugin and theme inventory to Afterware, shows
a risk report for plugins that are abandoned or unmaintained upstream, and, when
Afterware maintains a signed replacement build for a plugin you use, lets you switch
to it in place and restore the saved plugin files when needed. Every manifest and package is verified with
Ed25519 signatures and SHA-256 hashes before anything is installed; a failed
verification never installs and always notifies you.

= Connect your dashboard account =

After registration, open Afterware → Settings and choose "Get connection code". Copy the
15-minute code into your Afterware dashboard after selecting the intended workspace. The
site key stays in WordPress. Only an administrator who can manage plugins can request it;
the saved code is visible only to that administrator. A replacement invalidates the older
unused code. Issuing a code does not confirm account association or subscription payment.

This explicit action authenticates with the site's existing key and requests a connection
code. It sends no WordPress administrator email or password. Nothing is requested while
you view the Settings screen.

= What data is sent =

On each scan (daily via WP-Cron, or on demand from "Scan now"), the plugin sends an
inventory to Afterware. See "What does Afterware Connect send?" below
for the exact fields.

The plugin also downloads Afterware's
public signing key ring from keys.afterware.io once a day, so a rotated key is picked up
without a plugin update. That request sends no site key, no site address and no
identifying information — it is an anonymous download of a public file, the same for
every site.

When the build has a trusted signing key, these public-file refreshes can continue after
disconnecting. Disconnect stops authenticated site reporting and clears pending
installation-receipt delivery; it does not disable public signing-ring refreshes.

You can review the exact inventory request from the Settings screen, disconnect at any time
(this deletes the site key locally), and opt in separately before any debug-log
excerpt is attached to a support ticket.

New Afterware updates require current site coverage when paid delivery is enabled.
If the service refuses update access, installed plugins keep working and local reverts
remain available. Check your Afterware account's coverage and billing. A temporary service
outage can use a verified cached manifest for up to 14 days; explicit access denial cannot.

= What if my runtime does not meet a build’s requirements? =

Runtime requirements: a switch and an Afterware package download require explicit WordPress
and PHP minimums from the verified manifest that this site's numeric runtime versions meet.
Missing or unusual requirements need support review. The plugin screen explains a refusal;
a stale report cannot reopen a locally refused offer. These checks do not establish whole-site
compatibility. Test on staging and keep a full site backup. Local file revert remains a
separate recovery path and does not undo database upgrades.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/afterware` or install it from the
   WordPress plugin directory.
2. Activate it through the "Plugins" screen in WordPress. Network activation is not
   supported in this version; activate it per site.
3. Visit "Afterware" in the admin menu to connect and run the first scan.

== Frequently Asked Questions ==

= What does Afterware Connect send? =

Each inventory contains:

* Site data: schema version (a fixed protocol version, currently 1), site url,
  WordPress version, PHP version, multisite flag, locale, client version.
* Per plugin: slug, version, active, main file hash, readme hash, Update URI.
* Per theme: slug, version, active.

After the first successful installation of a package verified by Connect, background
delivery reports schema version 1, plugin slug, Afterware version and package SHA-256.
The existing site key authenticates the request. No browsing history, campaign identifier
or client timestamp is included. Afterware keeps one first receipt per registered site;
its time includes delivery delay and does not establish compatibility or payment.
Delivery checks that the expected build is still installed, retries up to six scheduled
attempts within seven days, and stops after acknowledgement. Disconnecting clears pending
delivery; uninstalling also removes its scheduled event. No receipt request runs while
displaying a screen or installing a package.

Registration sends the site URL; requesting a dashboard connection code uses the site
key. Support requests send the ticket text and plugin/environment context. Verification
failures can also open a bounded support ticket, as described below.

A debug-log excerpt is never included unless you opt in separately, per ticket, when
opening one.

= How are updates verified? =

Every manifest Afterware sends is Ed25519-signed, and the plugin verifies that
signature against a key it already trusts before reading anything out of it. That
trust is rooted in keys pinned in the plugin's own code: nothing fetched over the
network is trusted on its own, and a downloaded key ring only counts while its own
signature still verifies against a pinned key. Every package (the actual plugin zip)
is then checked against the SHA-256 hash named inside that already-verified manifest,
and must be served from an Afterware host, before installation.

This release pins two production Ed25519 public keys. The Settings screen's Key ring
card shows which keys your site trusts and any verified rotation or revocation status.

If either check fails, nothing is installed: the plugin logs the failure to its local
activity log and shows a persistent notice. When your site can reach Afterware it also
opens a support ticket, and the notice says so — at most one per affected plugin per
signing key id, so a single bad manifest cannot flood your ticket list.

= How does key rotation work? =

Afterware also publishes a signed key ring, so it can retire an old signing key and
bring a new one into use without a plugin update. This site checks for changes to that
ring once a day, and a ring only ever takes effect once it has verified against a key
this site already trusted — a new key vouched for by one this site already trusts, not
one supplied by whoever answers the request. A key Afterware revokes is never trusted
again afterwards, even if a later ring lists it as active again.

The Key ring card on the Settings screen shows every key this site currently trusts,
where it came from (pinned in the plugin, or fetched and verified), and when it was
last refreshed, with a "Refresh now" button and a notice if the stored ring has not
been refreshed in over 30 days. The same is available from WP-CLI:
`wp afterware keys` lists it, `--refresh` fetches it now, and `--forget` (with `--yes`)
clears a fetched ring back to only the pinned keys, for recovering from a ring accepted
by mistake.

= How do I go back to the original plugin? =

Connect refuses an offered build older than your installed plugin version. Keep the
current version until a suitable maintained build is available. Test on staging and keep
a full site backup: a new build may run database upgrades, which a file revert does not undo.

Every switch keeps a backup of the plugin exactly as it was before Afterware replaced
it (see "Where are backups stored and are they protected?" below). From that plugin's
screen under "Afterware" in the admin menu, the "Revert" button restores that backup
and reactivates it if the plugin was active — available for as long as the backup is
kept, which is until you delete Afterware Connect.

= Does it work on multisite? =

Per-site activation is supported; network-wide activation of Afterware Connect itself is
refused in this version, with a notice explaining why.

On a site where Connect is active, plugins that are activated for the whole network are
inventoried and reported like any other plugin, but they are not switchable: a
network-active plugin is one set of files every site in the network runs, and Afterware
Connect switches plugins per site only, so replacing them from one site's admin screen is
not something it will do. Its screens say so on the plugin in question. Plugins activated
on that site alone can be switched and reverted normally.

= Where are backups stored and are they protected? =

Before Afterware replaces a plugin it zips the copy already on your site into
`wp-content/afterware-backups/`. That copy is what the "Revert" button restores, so it
is kept even after you revert, and it is removed only when you delete Afterware
Connect.

The directory is closed to the web in two ways. It gets an `.htaccess` denying every
request (`Require all denied` on Apache 2.4, `Deny from all` on older Apache) and an
empty `index.php` so it can never be listed. Each archive is also named with sixteen
random hex characters — `demo-plugin-1.0.0-3f9ac1d0e2b47a58.zip` — so the URL of a
backup cannot be guessed even where those rules do not apply.

nginx does not read `.htaccess` files. If you run nginx, add this to your server block:

    location ^~ /wp-content/afterware-backups/ { return 404; }

Backups contain your plugin's own files. Symbolic links inside a plugin directory are
skipped rather than followed, and the number skipped is recorded in the activity log.

== Changelog ==

= 0.3.2 =
* Added Hungarian translations for the plugin interface.

= 0.3.1 =
* Point the plugin information link to the Afterware Connect installation guide.
* Display the publisher as Afterware (Complexolution Ltd.).

= 0.3.0 =
* Trust the production AWS KMS signing keys.
* Fetch the signed public key ring from keys.afterware.io.

= 0.2.0 =
* Learns rotated and revoked signing keys from the signed key ring at
  keys.afterware.io, verified against a key this site already trusts before
  it takes effect. Fetched once a day, or on demand from the Settings screen
  or WP-CLI.
* Settings screen: a Key ring card lists every key this site currently trusts,
  its status and where it came from, with a "Refresh now" button and a notice
  if the stored ring has not refreshed in over 30 days.
* `wp afterware keys` lists the trusted keys; `--refresh` fetches the ring
  now and reports what changed; `--forget` (with `--yes`) clears a fetched
  ring back to only the pinned keys, for recovering from a ring accepted by
  mistake.
* A revocation this site has verified is permanent: a revoked key is never
  trusted again, even if a later ring lists it as active.

= 0.1.0 =
* First release.
* Connect a site to Afterware and report its plugin and theme inventory, on demand
  or once a day, and store the risk report that comes back.
* Signed updates: every manifest is Ed25519-verified against a key pinned in this
  plugin's own code, and every package is checked against the SHA-256 hash inside
  that verified manifest before WordPress is allowed to install it. Anything that
  does not verify is refused, logged, shown as a notice and reported to support.
* Switch a stewarded plugin to its Afterware build, and revert it. Every switch
  zips the copy already on the site first and keeps that backup, so reverting
  restores the exact files — including whether the plugin was switched on.
* Support tickets from the admin, with the environment context Afterware needs and
  a debug-log excerpt only when you opt in to one.
* WP-CLI: `wp afterware status|scan|switch|revert|connect|tickets|ticket`.
* Admin screens: an overview of what Afterware knows about each plugin, a screen
  per plugin, settings that show exactly what is sent, tickets, and an activity
  log of everything this plugin has done locally.
