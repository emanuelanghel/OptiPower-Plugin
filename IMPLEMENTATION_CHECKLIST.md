# OptiPower - Implementation Checklist

This checklist tracks the WordPress plugin build from MVP to installable release.

## 1) Project Foundation
- [x] Create plugin folder/file structure for WordPress (`optipower/`).
- [x] Add main plugin bootstrap file with metadata and safe loading guards.
- [x] Add activation/deactivation/uninstall routines.

## 2) Core Data + Settings
- [x] Create a settings module with defaults:
  - Slow query threshold (ms)
  - Log retention (days)
  - Max log rows
  - Enable/disable monitoring
- [x] Create database table for query logs.
- [x] Add cleanup scheduler for old logs.

## 3) Runtime Monitoring (Functionality First)
- [x] Implement slow query capture at runtime (when available).
- [x] Persist captured slow queries with context:
  - SQL hash
  - Duration
  - Request URI
  - Source hint (plugin/theme/core best effort)
  - Timestamp
- [x] Add safeguard notices when deep query instrumentation is unavailable.

## 4) Insights + Recommendations
- [x] Add rules-based recommendation engine for each slow query bucket.
- [x] Add AI-ready abstraction layer (service interface) for future model integration.
- [x] Provide impact severity scoring (low/medium/high).

## 5) WordPress Admin + API
- [x] Add admin menu page for OptiPower.
- [x] Add REST API endpoints for live logs and summary data.
- [x] Add nonce/capability checks and sanitization for all inputs.

## 6) Realtime Panel (Minimal UI, No Final Design Yet)
- [x] Build functional live panel (auto-refresh) for top slow queries.
- [x] Add filters (time window, minimum duration).
- [x] Add recommendation display under each row.

## 7) Stability + Packaging
- [x] Add uninstall cleanup option (delete logs/settings).
- [x] Add readme with install/use instructions.
- [x] Run lint/syntax checks and basic smoke test flow.

## 8) Design Phase (Last)
- [ ] Define visual system (typography, color tokens, spacing, states).
- [ ] Refine admin UX for clarity and speed.
- [ ] Polish motion/interaction details.
- [ ] (Planned) Apply design skill workflow for UI refresh in final phase.
