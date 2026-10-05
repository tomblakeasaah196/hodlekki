Read and follow AGENTS.md at the repo root — all project rules live there, especially the Special Events CSS freshness contract.

- `assets/se/css/se.css` is a COMPILED, COMMITTED artifact (production serves committed bytes because cPanel has no Node).
- Touching SE paths (`assets/se/`, `e/`, `includes/special_events/`, `modules/special_events/`, `api/special_events_*`, `bin/build_se_css.sh`, `tests/special_events/`) requires rebuilding with `bash bin/build_se_css.sh` and committing `assets/se/css/se.css` and `assets/se/BUILD` in the same commit.
- Everything else outside SE paths cannot fail the check: Tailwind scanning is scoped to explicit `@source` dirs via `source(none)`, and CI gates the freshness check on SE diffs.
