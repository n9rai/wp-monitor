=== N9C Inside Monitor ===
Contributors: n9rai
Tags: security, monitoring, vulnerability, two-factor, hardening
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Security monitoring from the inside: known vulnerabilities in core, plugins and themes, risky configuration and admins without 2FA – numbers only.

== Description ==

N9C Inside Monitor reports security-relevant metrics of your WordPress installation to the N9C Inside Monitor service, where they are evaluated:

* known vulnerabilities in WordPress core, plugins and themes (also deactivated ones)
* missed security releases and WordPress versions without security updates
* administrators without two-factor login, number of administrators, an account named "admin", inactive administrators
* failed logins in the last 24 hours
* risky configuration: PHP errors shown to visitors, admin not forced to HTTPS, file editor enabled, open registration with a privileged default role, public debug.log, automatic core security updates disabled
* PHP files in the uploads directory (a typical sign of a web shell)
* WP-Cron not running

The service keeps a history, sends e-mail alerts for new critical findings and adds weekly outside scans of your domains (Server Check). The same service monitors TYPO3 installations with the extension `n9c_monitor`.

**Numbers and flags only.** No user names, e-mail addresses, IP addresses or content are transmitted. The admin page shows a data preview with exactly the JSON that is sent, and `wp n9c-monitor report --dry-run` prints it on the command line.

**Outgoing connections only.** The plugin opens no endpoint on your site. Reports are signed (HMAC-SHA256) and sent every 6 hours via WP-Cron.

An account in the N9C dashboard is required. [Register for free](https://dashboard.n9c.io/dashboard/register): full scope for 14 days, then free forever for one installation (score and open findings). History, e-mail alerts, weekly outside scans and more installations come with the subscription.

== External services ==

This plugin connects to the N9C Inside Monitor service operated by N9C (N9 Robotics GmbH, Germany). Nothing is sent before you connect the installation with a connection code from the N9C dashboard.

* **What is sent and when:** when connecting, the connection code, plugin and WordPress version and the site URLs. After that, every 6 hours (WP-Cron) and when you click "Send report now" or run `wp n9c-monitor report`: WordPress, PHP and database version, environment type, installed plugins and themes with version and active status, available updates known to WordPress, site URLs, the check results listed above as numbers or yes/no, and the paths (relative to wp-content, max. 20) of PHP files found in the uploads directory.
* **Endpoint:** https://api.n9c.io (agent API). The links in the admin page lead to https://dashboard.n9c.io.
* **Terms of use:** https://n9c.io/agb
* **Privacy policy:** https://n9c.io/datenschutz

The protocol is documented openly: https://github.com/n9rai/typo3-monitor/blob/main/Documentation/Protocol.md

== Installation ==

1. Install and activate the plugin (on multisite: network-activate it; one connection covers the whole network).
2. [Create a free account](https://dashboard.n9c.io/dashboard/register) in the N9C dashboard and create a connection code there.
3. Go to *Tools → N9C Inside Monitor* (multisite: *Network Admin → Settings → N9C Inside Monitor*), enter the code and click *Connect*. The first evaluation appears right away.

Alternatively with WP-CLI:

`wp n9c-monitor connect n9c-enroll-…`

= Configuration in wp-config.php (optional) =

* `N9C_MONITOR_INSTANCE`, `N9C_MONITOR_SECRET` – credentials as constants (or environment variables) instead of the database, e.g. for deployments.
* `N9C_MONITOR_AUTO_REPORT` – `false` disables the automatic report (use `wp n9c-monitor report` from a cron job instead).

= WP-CLI =

* `wp n9c-monitor connect <code> [--force]` – connect; `--force` reconnects and keeps instance and history
* `wp n9c-monitor report [--dry-run]` – send a report now, or show it without sending
* `wp n9c-monitor status` – connection and result of the last report
* `wp n9c-monitor disconnect` – remove the local credentials

== Frequently Asked Questions ==

= Which two-factor plugins are recognised? =

Two Factor, Wordfence Login Security, WP 2FA and Solid Security. WordPress itself has no two-factor login, so without one of these plugins every administrator counts as "without 2FA".

= What happens with staging copies of my site? =

The plugin remembers the address it was connected for. If a copy runs under a different address, automatic reports pause, and the admin page asks whether the site has moved (keep reporting) or is a copy (disconnect it).

= Does the plugin slow down my site? =

No. Collecting and sending runs in the WP-Cron background request every 6 hours. Logins of administrators and the number of failed logins per hour are recorded locally for the corresponding checks.

= Where do the vulnerability data come from? =

The N9C service matches the reported versions against the Wordfence Intelligence vulnerability database and the release data of wordpress.org.

== Changelog ==

= 0.1.0 =
* First version: report with schema n9c.agent.report/1, admin page with evaluation and data preview, WP-CLI commands, detection of moved or copied sites.
