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


header = (ROOT / "includes/header.php").read_text(encoding="utf-8")
manager = (ROOT / "assets/js/modal-manager.js").read_text(encoding="utf-8")
events = (ROOT / "modules/events/index.php").read_text(encoding="utf-8")

require('/assets/js/modal-manager.js' in header, "authenticated layout must load modal-manager.js")
require('class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10"' in header,
        "the main scroll container must remain untransformed")
require('main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-10 animate-' not in header,
        "animations on <main> create a containing block for fixed dialogs")
require('.app-modal-overlay:not(.app-modal-preserve-layout)' in header,
        "shared centered-dialog CSS is missing")
require('main.app-modal-scroll-locked' in header, "modal scroll lock CSS is missing")

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

# Events must keep all current overlays discoverable by the shared manager.
event_modal_ids = re.findall(
    r'<div\s+id="([^"]*[Mm]odal[^"]*)"\s+class="([^"]*\bfixed\b[^"]*)"',
    events,
)
require(len(event_modal_ids) >= 7,
        f"expected at least 7 Events dialog overlays, found {len(event_modal_ids)}")

# Every authenticated fixed overlay named as a modal must remain discoverable.
fixed_modal_count = 0
modal_sources = sorted((ROOT / "modules").glob("*/index.php"))
modal_sources.append(ROOT / "includes/charis_modals.php")
for path in modal_sources:
    source = path.read_text(encoding="utf-8", errors="ignore")
    for match in re.finditer(
        r'<[^>]+\bid="([^"]*[Mm]odal[^"]*)"[^>]+\bclass="([^"]*\bfixed\b[^"]*)"[^>]*>',
        source,
        re.IGNORECASE,
    ):
        fixed_modal_count += 1
        modal_id = match.group(1)
        require("modal" in modal_id.lower(), f"{path}: undiscoverable fixed dialog {modal_id}")

require(fixed_modal_count >= 100,
        f"modal scan unexpectedly found only {fixed_modal_count} authenticated overlays")

if failures:
    for failure in failures:
        print(f"FAIL: {failure}", file=sys.stderr)
    sys.exit(1)

print(f"Modal contract OK: {fixed_modal_count} authenticated overlays; {len(event_modal_ids)} Events dialogs.")
