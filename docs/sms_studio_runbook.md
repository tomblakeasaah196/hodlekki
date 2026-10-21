# SMS Studio — pre-flight runbook for a bulk send

Written after the 2026-10-21 audit (branch `claude/sms-cron-audit-r4yh04`),
when "Background sender stopped · last run 2 days ago" turned out to be a
crontab that had lost every hodlekki line. Everything below is what to check
**before** pressing *Send Campaign* on a large audience, and what to expect
while it runs.

## 1. The cron job (the sender)

`crontab -l` in cPanel → Terminal must contain these lines (the SMS worker
keeps its output in a log file on purpose — with `>/dev/null` a worker that
dies every minute leaves no trace anywhere):

```
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/sms_queue_worker.php >> /home/smartqaq/sms_worker.log 2>&1
* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php >/dev/null 2>&1
*/5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/special_events.php >/dev/null 2>&1
0 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/reach_lost_souls.php >/dev/null 2>&1
15 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/assimilation_watchlists.php >/dev/null 2>&1
```

To add any that are missing without touching the others:

```
cd ~ && crontab -l 2>/dev/null > cron.tmp; for L in \
"* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/sms_queue_worker.php >> /home/smartqaq/sms_worker.log 2>&1" \
"* * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/push_worker.php >/dev/null 2>&1" \
"*/5 * * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/special_events.php >/dev/null 2>&1" \
"0 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/reach_lost_souls.php >/dev/null 2>&1" \
"15 7 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/hodlc.lpc.cm/cron/assimilation_watchlists.php >/dev/null 2>&1"; \
do grep -qF "$L" cron.tmp || echo "$L" >> cron.tmp; done; crontab cron.tmp && rm cron.tmp && crontab -l
```

Proof that it works, in this order:

| Check | Command | Expect |
| --- | --- | --- |
| Worker runs by hand | `cd /home/smartqaq/public_html/hodlc.lpc.cm && /usr/local/bin/ea-php83 -d display_errors=1 cron/sms_queue_worker.php; echo exit=$?` | `[time] Worker: N sent, … still queued.` and `exit=0` |
| Cron fires | `tail -n 5 /home/smartqaq/sms_worker.log` | one `Worker:` line per minute |
| Studio agrees | SMS Studio status bar | green **Background sender running · Ns ago** |
| Migrations applied | `/usr/local/bin/ea-php83 db/migrate.php --status \| tail -n 8` | `Pending: (none)` |
| Errors | `tail -n 40 includes/error_log` | nothing from today |

The Studio tells three states apart: **stopped** (the job is not firing:
cPanel → Cron Jobs), **fires but does not finish · reason** (the job runs but
each run fails or finds the lock held: read the reason, `includes/error_log`
and `/home/smartqaq/sms_worker.log`), and **running**.

## 2. BulkSMS account (bulksmsnigeria.com)

| Page | Confirm |
| --- | --- |
| Sender IDs | `HODLEKKI` shows **Approved**. An unapproved sender ID refuses every message (BSNG-2011). |
| Wallet | Balance ≥ recipients × pages × ₦6.49 (ex VAT; VAT is charged when funding). 700 one-page texts ≈ ₦4,543; two pages ≈ ₦9,086. The Studio refuses to queue a campaign the wallet cannot pay for. |
| Append Sender ID | **Don't Append**. The Studio writes `HODLEKKI: ` at the start of every text itself; leaving *Always append* on can print the name twice and push a text onto a second page. |
| Corporate route | Only needed to reach DND-active numbers. Requires ₦20,000 balance plus CAC documents and a signed letter per network, and operator approval. Until it is approved, leave Gateway on the default (`direct-refund`): DND-active numbers do not receive the text and those units are refunded. |

## 3. SMS Studio → Settings

- **Sender ID**: exactly `HODLEKKI` (unlock with your password; *Fetch mine* lists the account's approved IDs).
- **Gateway**: Default (direct-refund) until the corporate route is approved.
- **Sender name in the text**: default — every text begins `HODLEKKI: `.
- **Price per SMS unit**: `6.49` (or the price of the route you use) — the wallet check multiplies units by this.
- **Test Connection** must say `Connection OK. Balance: ₦…`. If it says *balance not returned*, the wallet check cannot read the balance and the send will be refused — report the raw reply before sending.
- The checklist on that tab must be all ticks except the optional webhook lines.

## 4. Before the campaign

1. **Suppression tab**: review the list. Numbers there get nothing (₦0). Release anyone who should receive this send.
2. **Campaigns tab**: nothing should be *Queued* or *Sending* from earlier — the worker sends in id order, so old rows go out first and spend the wallet.
3. **Audience**: pick it, *Select shown*, and read the info line: valid numbers, invalid left out, shared numbers merged, numbers left out (opted out / marked invalid).
4. **Message**: keep the rendered text (with the `HODLEKKI: ` prefix, which the preview shows) under 160 GSM-7 characters for one page. The counter warns when the prefix pushes someone onto a second page. The naira sign `₦`, emoji and a few symbols switch a text to Unicode (70 characters per page, about three times the bill); write `NGN` or `N` instead.

## 5. The one test that proves everything

1. *Send Test* to your own phone with the exact campaign text. Expect: a toast `Test message sent…`, then History → the message → **BulkSMS message ID** present, status moving from *Submitted* to *Delivered* within a few minutes (polling runs after each send phase).
2. On the phone: sender shows `HODLEKKI`, the text starts with `HODLEKKI: ` **once**, and it arrived as one message. If the name shows twice, set *Append Sender ID* to *Don't Append* on the dashboard. If it arrived as two messages, shorten the text.
3. Your own number will then be **Blocked · duplicate (₦0)** in the real campaign if it is in the audience (same text to the same number within 24 h is refused on purpose — carriers drop repeats). Either accept that row or remove yourself from the selection.

## 6. Pressing Send, and while it runs

- The confirm dialog shows recipients, units, the cost at your unit price and the wallet balance; *Yes, send it* is disabled while the wallet is short.
- With the cron running, ~50 messages go out per minute: 700 recipients ≈ 15 minutes. You can close the page. Keep the Studio open on a desktop anyway as a safety net: if the cron stops, any open Studio tab sends the queue (25 s runs, ~20 messages each, noticeably slower).
- A temporary refusal from BulkSMS (rate limit, gateway busy, a 5xx) is retried a minute later, up to three tries; nothing is billed for a refusal. An empty wallet pauses the send instead of failing the rest; fund it and the worker resumes on its own.
- Delivery reports arrive by polling after the sending phase, about 30 per minute, newest first. Judge *Failed* only after the campaign shows 100 % handed over and a few minutes have passed.

## 7. After the send

- History → filter **Failed**: a DND-active number on the default route shows as failed and its unit is refunded by BulkSMS (the Studio shows the cost at submission; the wallet is the authoritative figure). Those people can only be reached through the corporate route.
- *Resend* on a single message is allowed for failed and unknown rows; an *Outcome unknown* row asks for confirmation because the message may already have gone out.
- After three carrier failures in a row a number is auto-suppressed; check the Suppression tab after a large send and release numbers that were only DND-blocked if the corporate route becomes available.
