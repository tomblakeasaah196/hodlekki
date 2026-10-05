#!/usr/bin/env python3
"""Static regression checks for the Charis welfare workflow."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
api = (ROOT / "api/charis_api.php").read_text(encoding="utf-8")
page = (ROOT / "modules/charis/index.php").read_text(encoding="utf-8")
modals = (ROOT / "includes/charis_modals.php").read_text(encoding="utf-8")
report = (ROOT / "includes/charis_awol_pdf.php").read_text(encoding="utf-8")
helper = (ROOT / "includes/charis_helpers.php").read_text(encoding="utf-8")
migration = (ROOT / "db/migrations/20261005093000_charis_welfare_integrity.sql").read_text(encoding="utf-8")

failures = []


def require(condition: bool, message: str) -> None:
    if not condition:
        failures.append(message)


for action in (
    "fetch_dashboard",
    "assign_welfare_case",
    "request_welfare_case",
    "approve_welfare_case",
    "update_awol_status",
    "resolve_awol_case",
    "save_charis_note",
    "fetch_welfare_archive",
    "get_awol_config",
    "save_awol_config",
):
    require(f"case '{action}':" in api, f"missing welfare API action: {action}")

require("charis_access_context($pdo, $user_id)" in api, "API must enforce Charis module access")
require("HTTP_X_CHARIS_CSRF" in api, "state-changing API calls must enforce CSRF")
require("X-Charis-CSRF" in page, "Charis page must send its CSRF token")
require("assim_attendance_union_sql()" in api or "assim_attendance_union_sql()" in helper,
        "AWOL detection must use the shared attendance union")
require("SELECT user_id FROM attendance WHERE event_id" not in api
        and "SELECT user_id FROM attendance WHERE event_id" not in helper,
        "AWOL detection must not fall back to the incomplete attendance table")
require("resolved_at = NOW()" in api, "case resolution must persist a resolution timestamp")
require("followup_id\" id=\"resolveWelfareFollowupId" in modals,
        "manual welfare resolution must carry its follow-up ID")
require("charis_secure_welfare_notes" in api and "charis_secure_welfare_notes" in report,
        "dashboard and PDF must apply the same note-visibility rules")
require("$charis_access['is_manager']" in report,
        "confidential AWOL reports must remain restricted to Charis leadership")
require("createdOnOrBefore" in helper and "$date_end . ' 23:59:59'" in report,
        "historical PDFs must not expose notes written after the report period")
require("function inlineArg" in page and "function jsEscape" not in page,
        "dynamic inline handlers must HTML-escape JSON string arguments")
require("charis_parse_date" in report and "366" in report,
        "custom report ranges must be validated and bounded")
require("data:image/png;base64" in report, "the PNG report logo must use the correct data URI MIME type")
require("CREATE TABLE IF NOT EXISTS charis_welfare_notes" in migration,
        "welfare notes need a forward migration")
require("column_name = 'resolved_at'" in migration,
        "welfare assignments need a guarded resolved_at migration")

for modal_id in ("birthdaysOverviewModal", "lifeEventsOverviewModal", "welfareOverviewModal", "awolConfigModal"):
    require(f'id="{modal_id}"' in modals, f"missing compact overview/config modal: {modal_id}")

api_actions = set(re.findall(r"case\s+'([^']+)'", api))
front_actions = set(re.findall(
    r'(?:name="action"\s+value="|action\s*:\s*[\'\"]|action\s*=\s*[\'\"])([a-zA-Z0-9_]+)',
    page + modals,
))
missing_actions = sorted(front_actions - api_actions)
require(not missing_actions, "front-end actions without API cases: " + ", ".join(missing_actions))

if failures:
    for failure in failures:
        print(f"FAIL: {failure}", file=sys.stderr)
    sys.exit(1)

print(f"Charis welfare contract OK: {len(front_actions)} front-end actions; all welfare guards present.")
