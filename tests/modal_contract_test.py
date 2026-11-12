#!/usr/bin/env python3
"""Static regression checks for the shared authenticated-modal contract."""

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
failures = []


def require(condition: bool, message: str) -> None:
    if not condition:
        failures.append(message)


# The shell moved from includes/header.php into includes/ui/shell.php, and the
# shared dialog CSS from header.php's inline <style> into the compiled
# stylesheet's source (assets/ui/src/app.css) — the UI foundation, PR 1.
header = (ROOT / "includes/ui/shell.php").read_text(encoding="utf-8")
styles = (ROOT / "assets/ui/src/app.css").read_text(encoding="utf-8")
manager = (ROOT / "assets/js/modal-manager.js").read_text(encoding="utf-8")
events = (ROOT / "modules/events/index.php").read_text(encoding="utf-8")

require('/assets/js/modal-manager.js' in header, "authenticated layout must load modal-manager.js")
require('<main id="main" class="ui-main"' in header,
        "the main scroll container must remain untransformed")
main_rule = re.search(r"\.ui-main \{(.*?)\}", styles, re.S)
require(main_rule is not None and "transform" not in main_rule.group(1) and "animation" not in main_rule.group(1),
        "animations on <main> create a containing block for fixed dialogs")
require('.app-modal-overlay:not(.app-modal-preserve-layout)' in styles,
        "shared centered-dialog CSS is missing")
require('main.app-modal-scroll-locked' in styles, "modal scroll lock CSS is missing")

for marker, description in (
    ("blockOutsideDismissal", "outside pointer/click dismissal guard"),
    ("event.key === 'Escape'", "Escape-key dismissal guard"),
    ("event.key !== 'Tab'", "keyboard focus trap"),
    ("handleFocus", "focus containment"),
    ("isolate(top)", "background inerting"),
    ("state.opener", "trigger focus restoration"),
    ("portalToViewport", "protection from transformed ancestors"),
    ("touchstart", "mobile outside-touch dismissal guard"),
    ("MutationObserver", "automatic future-modal discovery"),
):
    require(marker in manager, f"modal manager is missing {description}")

# Events moved to the components' native <dialog> (docs/UI.md): every dialog
# comes from ui_dialog_start(), and no hand-made fixed overlay is left.
event_modal_ids = re.findall(r"ui_dialog_start\('([^']+)'", events)
require(len(event_modal_ids) >= 8,
        f"expected at least 8 Events dialogs from ui_dialog_start(), found {len(event_modal_ids)}")
require(not re.search(r'<div\s[^>]*\bclass="[^"]*\bfixed\b', events),
        "Events must not hand-build fixed overlays; use ui_dialog_start()")

# Every authenticated fixed overlay named as a modal must remain discoverable.
FIXED_MODAL = r'<[^>]+\bid="([^"]*[Mm]odal[^"]*)"[^>]+\bclass="([^"]*\bfixed\b[^"]*)"[^>]*>'
fixed_modal_count = 0
modal_sources = sorted((ROOT / "modules").glob("*/index.php"))
modal_sources.append(ROOT / "includes/charis_modals.php")
for path in modal_sources:
    source = path.read_text(encoding="utf-8", errors="ignore")
    for match in re.finditer(FIXED_MODAL, source, re.IGNORECASE):
        fixed_modal_count += 1
        modal_id = match.group(1)
        require("modal" in modal_id.lower(), f"{path}: undiscoverable fixed dialog {modal_id}")

# Prove the scan still works with a sample (a broken pattern finds nothing).
# The count itself falls on purpose as pages move to the components' native
# <dialog> (docs/UI.md): 126 before the UI migration, 98 after batch 1, 15 by
# Envision, and 0 once the last legacy page has moved. A count floor broke
# each time a page moved, so the pattern is tested instead.
require(re.search(FIXED_MODAL, '<div id="sampleModal" class="fixed inset-0 z-50 hidden">', re.IGNORECASE) is not None,
        "the modal scan pattern no longer matches a fixed overlay")

if failures:
    for failure in failures:
        print(f"FAIL: {failure}", file=sys.stderr)
    sys.exit(1)

print(f"Modal contract OK: {fixed_modal_count} authenticated overlays; {len(event_modal_ids)} Events dialogs.")
