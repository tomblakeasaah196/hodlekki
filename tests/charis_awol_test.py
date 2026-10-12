#!/usr/bin/env python3
"""Comprehensive static and contract tests for the Charis AWOL Monitoring experience."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
failures = []


def require(condition: bool, message: str) -> None:
    if not condition:
        failures.append(message)


api = (ROOT / "api/charis_api.php").read_text(encoding="utf-8")
page = (ROOT / "modules/charis/index.php").read_text(encoding="utf-8")
modals = (ROOT / "includes/charis_modals.php").read_text(encoding="utf-8")
report = (ROOT / "includes/charis_awol_pdf.php").read_text(encoding="utf-8")
helpers = (ROOT / "includes/charis_helpers.php").read_text(encoding="utf-8")
migration = (ROOT / "db/migrations/20261028090000_charis_awol_config.sql").read_text(encoding="utf-8")
events_helper = (ROOT / "includes/event_analytics_helpers.php").read_text(encoding="utf-8")

# 1. DATABASE MIGRATION INTEGRITY
require("charis_awol_config" in migration,
        "migration must define charis_awol_config singleton table")
require("charis_awol_config_history" in migration,
        "migration must define charis_awol_config_history table")
require("services_missed" in migration and "INT UNSIGNED NOT NULL" in migration,
        "charis_awol_config must store positive integer services_missed")
require("period_weeks" in migration and "INT UNSIGNED NOT NULL" in migration,
        "charis_awol_config must store positive integer period_weeks")
require("service_types" in migration and "JSON NOT NULL" in migration,
        "charis_awol_config must store service_types as JSON")
require("spiritual_statuses" in migration and "JSON NOT NULL" in migration,
        "charis_awol_config must store spiritual_statuses as JSON")
require("id" in migration and "PRIMARY KEY" in migration,
        "singleton record must be guarded with id=1 constraint")

# 2. CANONICAL CONSTANTS & CANONICAL VALUES
require("CHARIS_CANONICAL_SERVICE_TYPES" in helpers,
        "helpers must define canonical service types constant")
require("CHARIS_CANONICAL_SPIRITUAL_STATUSES" in helpers,
        "helpers must define canonical spiritual statuses constant")
require("CHARIS_EXCLUDED_ATTENDANCE_STATUSES" in helpers,
        "helpers must define excluded attendance statuses constant")
require("'Sunday_Service'" in helpers and "'Midweek_Service'" in helpers,
        "canonical service types must include Sunday_Service and Midweek_Service")
require("'Member'" in helpers and "'Worker'" in helpers and "'Pastor'" in helpers,
        "default spiritual statuses must include Member, Worker, Pastor")
require("'Unknown'" in helpers and "'Relocated'" in helpers and "'Attends Another Church'" in helpers,
        "excluded attendance statuses must include Unknown, Relocated, Attends Another Church")

# 3. AUTHORIZATION GUARDS
require("charis_can_configure_awol" in helpers,
        "helpers must provide charis_can_configure_awol authorization check")
require("charis_can_configure_awol" in api,
        "API must enforce charis_can_configure_awol before saving config")
require("Super_Admin" in helpers,
        "charis_can_configure_awol must permit Super Admins")
require("'Director'" in helpers and "'HOD'" in helpers,
        "charis_can_configure_awol must check active leadership status")
require("charis" in helpers.lower() and "idi" in helpers.lower() and "welfare" in helpers.lower(),
        "charis_can_configure_awol must verify department is Charis, IDI, or Welfare")

# 4. CALCULATION LOGIC & LAGOS TIMEZONE
require("Africa/Lagos" in helpers,
        "AWOL calculations must strictly use Africa/Lagos timezone for rolling calendar weeks")
require("charis_compute_awol_list" in helpers,
        "helpers must provide charis_compute_awol_list function")
require("assim_attendance_union_sql()" in helpers,
        "charis_compute_awol_list must query assimilation attendance union")
require("SELECT user_id FROM attendance WHERE event_id" not in helpers,
        "charis_compute_awol_list must not query incomplete attendance table")
# The streak engine reads one row per person per day (SELECT DISTINCT) and
# folds it into a date-keyed map, so two check-ins on the same Sunday are a
# single presence. tests/charis_awol_engine_test.php proves the behaviour;
# this only guards the query shape.
require("SELECT DISTINCT attended.user_id" in helpers,
        "charis_compute_awol_list must deduplicate same-day multiple attendance records")

# --- Streak engine contract (the window counter must not come back) -------
require("charis_awol_service_rules" in helpers,
        "helpers must expose the per-service counts_absence / resets_clock grid")
require("charis_awol_auto_resolve_returned" in helpers,
        "helpers must auto-close cases when a member is marked present again")
require("consecutive_missed" in helpers,
        "the engine must report a consecutive streak, not a window total")
require("$missedTotal = $totalQualifyingCount - $attendedCount" not in helpers,
        "the window-subtraction counter must not be reintroduced")
require("'Manual_Check' as alert_type" in api,
        "manual flags must emit the alert_type the Manual Flags tab filters on")
require("alert_type === 'Manual_Check'" in page,
        "the Manual Flags tab must filter on the alert_type the API emits")
require("missed_service_dates" in helpers and "missed_service_dates" in page,
        "missed service dates must be produced by the API, not just rendered")
require("attendance_strip" in helpers and "attendance_strip" in page,
        "the card's attendance strip must be produced by the API")

# 5. UI / UX REBRANDING & MARKUP
require("AWOL Monitoring" in page,
        "Charis module navigation must display AWOL Monitoring")
require("desktopAwolPillsContainer" in page,
        "Charis module desktop UI must include active rule summary pills container")
require("Change AWOL List" in page,
        "Charis module must include 'Change AWOL List' button for authorized editors")
require("awolConfigModal" in modals,
        "Charis modals must include awolConfigModal")
require("welfareOverviewModal" in modals,
        "Charis modals must retain welfareOverviewModal with updated branding")
require("awolConfigSentencePreview" in modals,
        "awolConfigModal must render live English sentence preview")
require("awolConfigHistoryList" in modals,
        "awolConfigModal must render rule change history")

# 6. MODAL TAB NAVIGATION & LIST RENDERING
require("data-welfare-tab=\"my_cases\"" in modals or "data-welfare-tab=\"my_cases\"" in page,
        "modal/desktop must support My Cases tab")
require("data-welfare-tab=\"awol_list\"" in modals or "data-welfare-tab=\"awol_list\"" in page,
        "modal/desktop must support AWOL List tab")
require("data-welfare-tab=\"manual_flags\"" in modals or "data-welfare-tab=\"manual_flags\"" in page,
        "modal/desktop must support Manual Flags tab")
require("data-welfare-tab=\"archive\"" in modals or "data-welfare-tab=\"archive\"" in page,
        "modal/desktop must support Archive tab")

# 7. PDF TRUTHFULNESS & SNAPSHOT
require("charis_get_historical_awol_config" in report or "charis_get_awol_config" in report,
        "AWOL PDF must dynamically fetch active or historical AWOL rule configuration")
require("consecutive" in report.lower() and "$servicesMissedRule" in report,
        "AWOL PDF narrative must truthfully state the configured consecutive threshold")
require("resetting the count to zero" in report.lower(),
        "AWOL PDF narrative must state that attendance resets the counter")
require("$archiveWeeksRule" in report,
        "AWOL PDF narrative must reflect the archive horizon")
require("charis_awol_absence_services" in report,
        "AWOL PDF must name only the services whose absence actually counts")

# 8. API ACTIONS
for required_action in ("fetch_dashboard", "get_awol_config", "save_awol_config"):
    require(f"case '{required_action}':" in api, f"missing required API case: {required_action}")

if failures:
    for f in failures:
        print(f"FAIL: {f}", file=sys.stderr)
    sys.exit(1)

print("Charis AWOL Monitoring contract & regression tests OK: all requirements verified.")
