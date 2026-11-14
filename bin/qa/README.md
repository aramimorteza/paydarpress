# QA scripts

Used for the 0.32 release checks (paths assume the build machine: WordPress sources in `/tmp/wp/wp65` and `/tmp/wp/wp69`, SQLite integration in `/tmp/wp/sqlite`, release zips in `dist/`).

- `upgrade-test.sh <old-version> [wp69|wp65]` — fresh site with an old release, content and a scan; then the current code over it. Prints schema gaps, PHP errors and the integration test result.
- `uninstall-check.php <site> <keep|delete>` — deletes the plugin and lists what is left (tables, options, user meta, capabilities, pending actions).
- `../../plugin/paydarpress/tests/integration.php <site>` — integration tests (leave no trace).
