=== Greyrock Wordfence-Cloudflare Synchroniser ===
Contributors: linus-007
Tags: wordfence, cloudflare, firewall, security, multisite
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises current and historical Wordfence firewall blocks with Cloudflare Zone Access Rules or an account-level IP list.

== Description ==

Greyrock Wordfence-Cloudflare Synchroniser moves Wordfence block intelligence to Cloudflare so unwanted traffic can be stopped at Cloudflare's network edge before it reaches the WordPress server.

The plugin supports two Cloudflare destinations:

= Zone Access Rules =

Creates Cloudflare block rules for one zone.

Use this mode when the Wordfence blocks should protect one Cloudflare zone.

= Account IP List =

Adds addresses to a reusable Cloudflare account-level IP list.

Use this mode when several domains or Cloudflare zones should share the same list.

An Account IP List does not block traffic by itself. You must create a Cloudflare Custom Rule with the Block action in every zone that should use the list.

Example rule:

`ip.src in $wordfence_hot_blocklist`

The recommended list name is:

`wordfence_hot_blocklist`

= Current and historical Wordfence blocks =

The plugin synchronises:

* Current Wordfence blocks when the installed Wordfence version exposes its active-block API.
* Historical Wordfence WAF events recorded as `blocked:waf` in the Wordfence hits table.

Historical synchronisation is configurable:

* Lookback period: 1, 3, 6, 12 or 24 hours.
* Minimum blocked events per IP address: 1 through 100.
* Default lookback: 24 hours.
* Default threshold: 1 event.

Repeated events from the same address are deduplicated before synchronisation. Invalid, private and reserved addresses are rejected.

= Multisite support =

When network activated:

* Network Admin can provide shared Cloudflare settings.
* Individual sites may inherit the network configuration or use site-specific settings when overrides are allowed.
* Network Admin includes a Synchronise Network Now action for inheriting sites.
* Network Admin includes a combined Synchronisation Log.
* Individual sites retain their own synchronisation logs and manual IP block pages.
* Sites using independent settings retain their own site-level controls.

= Manual management and diagnostics =

The plugin provides:

* Saved Cloudflare configuration validation.
* A diagnostic add-and-remove test.
* Manual account-list add and remove controls.
* A required reason when manually adding an address to an account list.
* Manual site-level IP blocking.
* Synchronisation logs.
* Cleanup and reconciliation controls where ownership of Cloudflare entries is isolated to one site.

= Security =

Use a restricted Cloudflare API token. A Global API Key is not required and should not be used.

For Zone Access Rules mode, the token requires:

* Zone - Firewall Services: Edit
* Zone - Zone: Read

For Account IP List mode, the token requires:

* Account - Account Rule Lists: Edit

Restrict the token to only the required Cloudflare account or zones.

The plugin does not require DNS editing permission.

= Independence =

Greyrock Wordfence-Cloudflare Synchroniser is developed by Greyscale Zone.

This plugin is not affiliated with Wordfence or Cloudflare.

== Installation ==

1. Install and activate Wordfence.
2. Install and activate Greyrock Wordfence-Cloudflare Synchroniser.
3. Open Greyrock Synchroniser in WordPress administration.
4. Select Zone Access Rules or Account IP List mode.
5. Create a restricted Cloudflare API token with the permissions required for the selected mode.
6. Enter the required Cloudflare identifiers.
7. Save the settings.
8. Validate the saved Cloudflare configuration.
9. Run the diagnostic test block.
10. Configure the historical WAF lookback and event threshold.
11. Run synchronisation.

For multisite installations, network activate the plugin and configure shared settings from Network Admin when appropriate.

= Zone Access Rules mode =

Required settings:

* Cloudflare API Token
* Cloudflare Zone ID

= Account IP List mode =

Required settings:

* Cloudflare API Token
* Cloudflare Account ID
* Cloudflare List Name

The plugin resolves the Cloudflare list's internal identifier automatically.

After configuring the list, create a Cloudflare Custom Rule with the Block action in every zone that should use it.

== Frequently Asked Questions ==

= Does the plugin require a Cloudflare Global API Key? =

No. Use a restricted Cloudflare API token.

= Does an Account IP List block traffic by itself? =

No. Create a Cloudflare Custom Rule with the Block action in every zone that should use the list.

= Can one Cloudflare list protect several domains? =

Yes. Account IP List mode can maintain one reusable account-level list. Each Cloudflare zone must have a Custom Rule that references the list.

= Does the plugin synchronise historical Wordfence firewall events? =

Yes. It can read historical Wordfence WAF events recorded as `blocked:waf` within the configured lookback period.

= What historical lookback periods are available? =

1, 3, 6, 12 and 24 hours.

= Can I require several Wordfence block events before an IP is sent to Cloudflare? =

Yes. The historical block threshold accepts whole numbers from 1 through 100.

= What happens if the installed Wordfence version does not expose its active-block API? =

The plugin continues using the verified historical Wordfence WAF event table instead of terminating synchronisation.

= Does the plugin support WordPress multisite? =

Yes. It supports shared Network Admin settings, optional site-specific overrides, network-wide synchronisation for inheriting sites and a combined Network Admin synchronisation log.

= Why are cleanup and reconciliation unavailable on some inheriting sites? =

An inheriting site may share a Cloudflare destination with other sites. Its local log cannot safely determine whether another site still requires the same Cloudflare entry.

= Are private or reserved IP addresses sent to Cloudflare? =

No. Invalid, private and reserved addresses are rejected during historical event processing.

= Is the plugin affiliated with Wordfence or Cloudflare? =

No.

== Changelog ==

= 1.1.5 =

* Added configurable historical Wordfence WAF lookback.
* Added a configurable minimum historical event threshold.
* Added numeric validation for historical settings.
* Added Network Admin synchronisation for sites inheriting shared settings.
* Added a combined Network Admin synchronisation log.
* Added reasons for manually added Cloudflare account-list entries.
* Prevented fatal errors when the installed Wordfence version does not expose `wfBlock::getBlocks()`.
* Updated compatibility metadata for WordPress 7.0.

= 1.1.2 =

* Added Cloudflare Account IP List mode.
* Added automatic list identifier resolution from the visible list name.
* Added manual account-list add and remove controls.
* Added Cloudflare configuration validation and diagnostic block testing.
* Corrected Cloudflare account-list item deletion.
* Updated Greyrock branding and release packaging.

== Upgrade Notice ==

= 1.1.5 =

Adds historical WAF synchronisation, multisite Network Admin controls, a combined network log and compatibility with current Wordfence releases.
