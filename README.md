# OptiPower Plugin

OptiPower is a lightweight WordPress performance plugin focused on one of the biggest hidden bottlenecks: slow database queries.

It gives you a real-time monitoring panel inside `wp-admin`, highlights query hotspots, and provides actionable recommendations so you can improve site speed with confidence.

## Why OptiPower

- Detects slow SQL queries during real requests.
- Shows probable source (`plugin`, `theme`, `core`, or `unknown`).
- Assigns severity (`low`, `medium`, `high`) by impact.
- Suggests optimization actions for each logged query.
- Includes retention and cleanup controls to keep logs manageable.

## Current MVP Features

- WordPress plugin bootstrap with activation/deactivation hooks
- Query log database table (`wp_optipower_query_logs`)
- Runtime slow-query capture (when `SAVEQUERIES` is enabled)
- Rules-based recommendation engine
- AI-ready service abstraction for future model integration
- Admin dashboard page with:
  - Settings management
  - Auto-refresh live table
  - Duration filtering
- REST API endpoints for logs and summary
- Uninstall cleanup option (optional data deletion)

## Requirements

- WordPress `6.0+`
- PHP `7.4+`

## Installation

1. Clone or download this repository.
2. Copy the `optipower` folder into your WordPress plugins directory:
   - `/wp-content/plugins/optipower`
3. In WordPress Admin, go to `Plugins` and activate **OptiPower**.
4. Open `OptiPower` from the left admin menu.

## Enable Deep Query Monitoring

For detailed query timing, add this in `wp-config.php`:

```php
define('SAVEQUERIES', true);
```

Without this flag, OptiPower still loads but deep query instrumentation is limited.

## Usage

1. Go to `wp-admin > OptiPower`.
2. Configure:
   - Slow query threshold (ms)
   - Log retention (days)
   - Max log rows
3. Use the realtime panel to inspect:
   - Slow query duration
   - Source hint
   - Request URI
   - Severity
   - Recommendation

## REST Endpoints

All endpoints are namespaced under:

- `/wp-json/optipower/v1/logs`
- `/wp-json/optipower/v1/summary`

Access requires admin capability (`manage_options`).

## Project Structure

```text
optipower/
  optipower.php
  uninstall.php
  assets/
    css/admin.css
    js/admin.js
  includes/
    class-optipower.php
    class-optipower-settings.php
    class-optipower-db.php
    class-optipower-monitor.php
    class-optipower-recommendations.php
    class-optipower-ai-service.php
    class-optipower-rest.php
    class-optipower-admin.php
```

## Roadmap

- Expand time-window filtering (15m / 1h / 24h)
- Add plugin/theme impact ranking
- Add trend analytics and performance score
- Add safe one-click optimization toggles
- Finalize premium admin design phase

## Contributing

Issues and pull requests are welcome. Keep changes focused, tested, and aligned with plugin performance goals.

## License

This repository currently includes an MIT `LICENSE` file at root.  
If you plan to publish on the official WordPress.org directory, consider switching to a GPL-compatible license.
