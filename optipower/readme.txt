=== OptiPower ===
Contributors: optipower
Tags: performance, optimization, database, monitoring
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight performance plugin for WordPress with slow query monitoring and actionable recommendations.

== Description ==

OptiPower helps you identify and prioritize slow database queries.

Features:
- Realtime slow query panel in WP Admin.
- Rules-based optimization recommendations.
- Severity scoring and source hints (plugin/theme/core/unknown).
- Retention and cleanup settings.

To capture detailed query timings, enable:
`define('SAVEQUERIES', true);` in `wp-config.php`.

== Installation ==

1. Upload `optipower` folder to `/wp-content/plugins/`.
2. Activate **OptiPower** from the Plugins screen.
3. Open **OptiPower** in wp-admin menu.
4. Configure threshold/retention settings.

== Changelog ==

= 0.1.0 =
* Initial MVP release.

