# Changelog

All notable changes to this project are documented here. Dates are when the change was made, not necessarily when it was deployed.

## 2026-08-23

### Fixed

- **IESO (Canada) never refreshed without cron.** `cron/fetch_ieso.php` and `ukgrid_ingest_ieso()` already existed, but `IESO` was missing from `refresh.intervals_minutes` and `refresh.timeouts_seconds` in both `includes/config.php.example` and `includes/refresh.php`. On-demand refresh (the default, no-cron mode described in `README-DEPLOY.md`) silently never considered IESO overdue, so an install without cron access would have permanently stale Canada data with no error anywhere to point at it. Both files now include `'IESO' => 30` (minutes) and `'IESO' => 7` (seconds), and `includes/refresh.php`'s on-demand switch gained the missing `case 'IESO':` to actually call `ukgrid_ingest_ieso()`.
- **Inconsistent ENTSO-E country count in comments/log messages.** `includes/config.php.example`'s prose and the log line in `includes/ingest.php` (`ukgrid_ingest_entsoe()`) still described only five ENTSO-E-sourced country pages (France, Netherlands, Belgium, Norway, Denmark) even though `entsoe_countries` has included five more comparison-only countries (Germany, Spain, Italy, Sweden, Portugal) for a while. Both now correctly describe all ten.

### Added

- **Config-check panel on `tools/full-refresh.php`.** Before running anything, the manual refresh tool now checks the live `includes/config.php` for the two gaps above (and their general form) and displays what it finds:
  - `entsoe_api_token` left unset (`CHANGE-ME` or blank)
  - `eia_api_key` left unset
  - any of the ten expected ENTSO-E countries missing from `entsoe_countries`
  - any on-demand-refreshable source missing from `intervals_minutes` and/or `timeouts_seconds`
  - `refresh.on_demand` left off with no indication cron is configured instead

  A green "config check passed" line shows when nothing is wrong. This is meant to catch the next version of the IESO-style gap at a glance, on a page the operator already visits, rather than as silent missing data with no error to find.
- **Tighter, documented IESO timeout in the manual tool.** `tools/full-refresh.php`'s per-source timeout for IESO dropped from 15s to 8s, with a comment explaining why: `ukgrid_ingest_ieso()` makes two sequential HTTP calls (worst case ~16s already), IESO's servers are further from a UK host than this project's other sources, and a hang risks tripping a host-level proxy timeout that PHP can't catch or report on - so the tighter figure leaves more headroom under that unknown ceiling.

### Repository

- First public push of the project to GitHub, with `includes/config.php` and `tools/.htpasswd` excluded (see `.gitignore`) since both carry deployment-specific secrets.
