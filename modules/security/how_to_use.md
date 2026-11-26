# Security Centre — how to use

**Who can open it:** Super Admin and the Resident Pastor.
**Where:** sidebar → *Security & Stewardship* → **Security Centre**
(`/modules/security/`) — or search "Security" in the Ctrl/Cmd+K
palette (each tab — Accounts, Sessions, Sign-ins, Audit trail — is
searchable there too).

Access comes from **Roles & permissions** (module "Security Centre"): View opens
the screen, Edit allows changing an account, Manage allows generating sign-in
emails for everyone. While you *view as* someone the screen is read-only.

---

## 1. What each action actually does

| Action | Effect |
| ------ | ------ |
| **Reinstate** | `account_status = active`, failed-login counter cleared, lockout cleared. Frozen roles stay frozen. |
| **Suspend** | Login blocked. Every live session is signed out on their next click. Roles untouched. Reversible. |
| **Revoke** | Everything Suspend does **plus every system role is frozen**, so a reinstated account comes back with zero clearance until a Super Admin restores it. |
| **Reset password** | You type the new password. Password is re-hashed, every session is killed, and (by default) they must choose their own at next sign-in. |
| **Sign out everywhere** | Kills every live session for that member. They can sign straight back in — this is not a block. |
| **Clear lockout** | Removes an automatic lock caused by 5 failed sign-ins. |
| **Force change** | They land on `/auth/change_password` and cannot reach any other page until they set a new password. |
| **Restore roles** | Un-freezes roles frozen by a Revoke. **Super Admin only.** They must sign out and back in to pick the roles up. |

A reason is **required** for Suspend and Revoke. It is written to the audit
trail and shown to the member in the sign-in error message.

### Suspend vs Revoke — which do I use?

- **Suspend** — temporary. Someone is travelling, a phone was lost, a
  disciplinary matter is under review. You intend to switch them back on.
- **Revoke** — they have left the church, left a department for good, or the
  account was compromised. Their clearance is deliberately destroyed so nobody
  can quietly re-enable a pastor-level account by flipping one switch.

---

## 2. Guard rails you cannot override

1. **You cannot act on your own account here.** Change your own password at
   *My Profile → Security*; to change your own sign-in email, ask another
   admin to do it from this page.
2. **A Resident Pastor cannot act on a Super Admin.** Only a Super Admin can.
3. **The last active Super Admin cannot be suspended or revoked.** Promote a
   second Super Admin in Role Management first.

These are enforced server-side in `security_guard_target()`
(`includes/security_helpers.php`), not just hidden in the UI.

---

## 3. The four tabs

- **Accounts** — the roster. Search by name, email or phone; filter by status,
  lockout, "must change password", "no password set", "online now" or "has
  system roles". Problem accounts sort to the top. *Manage* opens the full
  control panel for one member.
- **Sessions** — every browser signed in over the last 7 days, with the
  device, IP and last activity. *Sign out* kills that one device.
- **Sign-ins** — successes and failures, with the failure reason
  (`bad_password`, `account_suspended`, `account_locked`, `unknown_email`…).
  Filter to failures only when you are investigating.
- **Audit trail** — who did what to whom, when, and from which IP. Every
  privileged action on this page writes a row here. Nothing deletes rows.

---

## 4. Resetting a password, step by step

1. **Accounts** tab → search for the member → **Manage**.
2. Scroll to **Reset password**.
3. Either type a password or press **Suggest password** (it fills both
   boxes with a 14-character random password).
4. Leave **Change it at next sign-in** ticked. This is the
   whole point: you know the temporary password, they must replace it.
5. Press **Set password** and confirm.
6. **Copy the password before you close the dialog — it is never shown again.**
   Read it to the member over the phone or in person, not over WhatsApp.

Everything they were signed into is signed out immediately.

---

## 5. Changing a member's sign-in email, step by step

The church no longer gives out `@hodlc.com` sign-in names (owner's decision,
13 Nov 2026). Members sign in with **their phone number** or **their own
email**. When a member confirms their own email, it takes the place of their
`@hodlc.com` name automatically; typing the old name then tells them to use
their personal email or their phone.

This card is only for a member who still has an old `@hodlc.com` name and
wants it shorter or easier to remember. For anyone else it is greyed out and
says how they sign in ("Signs in with their phone.").

1. **Accounts** tab → search for the member → **Manage**.
2. Scroll to **Sign-in email**. Their current name (and personal email, if
   they have one) is shown at the top of the card.
3. Type what they want *before* the `@` — `@hodlc.com` is added if you leave
   it out. Type it again in the confirm box.
4. If the account may be compromised, or you simply want them to sign in
   fresh, tick **Sign them out everywhere**.
5. Press **Save email** and confirm.
6. **Tell the member the new name** — the old one stops working the moment
   you press the button. Their password does not change.

The new name must end in `@hodlc.com` and must not already belong to another
member. Every change is written to the audit trail (`login_email_changed`,
showing the old and new name) and the member gets an in-app notification.

"Generate emails" is gone: a member with no email signs in with the phone
number on their record. To see who has neither a phone nor an email, filter
the roster by **No sign-in email** and check their phone.

---

## 6. Automatic protections (no admin action needed)

- **5 consecutive failed sign-ins** locks the account for **15 minutes** and
  writes an `account_auto_locked` audit row. Clear it early with *Clear lockout*.
- **Password policy** on every entry point (self-service change, admin reset,
  first-time setup): at least 10 characters, one uppercase, one lowercase, one
  number, and it may not contain the member's own name or email or an obvious
  word like `password`.
- **Session fixation** is blocked — the PHP session id is rotated on login.
- **"Keep me signed in on this device for 30 days"** (ticked by default on the
  sign-in screen) gives the device a token, so a member tapping a notification
  hours later lands on the page instead of the login screen. Only a sha256 of
  the token is stored (`auth_remember_tokens`). It obeys this Centre exactly as
  a session does: **Suspend**, **Revoke**, **Sign out everywhere**, a password
  change that signs other devices out, and **Sign out** on a single device in
  *Live Sessions* all end it, and so does the member signing out. A remembered
  device keeps **one** row in *Live Sessions* however often its PHP session
  expires, so revoking that row really is revoking the device. Unused for 30
  days, it lapses. Members should untick the box on shared or office computers.
- **`/auth/setup_password` is first-time only.** It used to let anyone who
  knew a member's first name and phone number reset their password from the
  open internet. It now refuses any account that already has a password, is
  rate-limited to 10 failures per IP per 15 minutes, and logs every blocked
  attempt as `first_time_setup_blocked`.

---

## 7. What members see on their side

*My Profile → Security & Password* lets any member:

- change their own password (current password required),
- see their last sign-in time and IP,
- see how old their password is,
- see every device signed in as them,
- press **Sign out all other devices**,
- see their **passkeys**, add one on the device in hand, or remove one.

### Passkeys (since 26 November 2026)

A passkey lets a member sign in with their phone's own fingerprint, face or
screen lock instead of typing their password.

- **Set-up:** after a password sign-in on a phone or computer that can make
  one and has none yet, a card offers **Turn on**. One tap and one fingerprint,
  and it's done. **Not now** hides the card on that device for 30 days.
  Profile → Passkeys → **Add passkey** does the same at any time.
- **Sign-in:** on a device that has one, the sign-in page shows a big
  fingerprint. Android and desktop Chrome/Edge open the prompt by themselves;
  iPhone needs one tap on the fingerprint. **Use password** is always there.
- **Other devices:** iCloud Keychain and Google Password Manager sync a
  passkey to the member's other devices on the same account. On a new device
  without one, "Have a passkey? Use it" can also scan a QR code with the phone.
- **Forgot password** is unchanged (email link). A passkey never replaces
  the password; suspensions and revocations stop passkey sign-ins too.
- Every passkey added or removed is in the member's security log
  (`passkey_added`, `passkey_removed`), and a passkey sign-in logs as
  "Signed in with a passkey from …".
- **A lost or taken phone:** the member removes that passkey in Profile.
  An administrator's **Reset password** here also removes **all** of the
  member's passkeys (a passkey does not use the password, so a reset alone
  would not stop one); the log line says how many. The member adds new ones
  at their next sign-in. A member's own "Forgot password" leaves passkeys alone.

---

## 8. Troubleshooting

**"Security tables are not installed yet."**
The migration has not run. On the server:
`php db/migrate.php` (or trigger a deploy — `bin/deploy.sh` runs it).

**A member says the app keeps signing them out.**
Ask whether they untick *Keep me signed in* when they sign in, and whether the
phone clears cookies or uses private browsing. Otherwise check the Audit Trail
for a Sign out everywhere, Suspend or password change on their account: each one
ends every remembered device, by design.

**A revoked member can still see pages.**
They cannot. The gate runs in `includes/db.php` on every request, page and API
alike, so the session dies on their next click — but a page already open in
their browser stays painted until they interact with it.

**Someone was reinstated but has no modules.**
Their roles are still frozen by the earlier Revoke. A Super Admin must press
**Restore roles**, and the member must sign out and back in.

**A member cannot sign in after their email was changed.**
They are probably still typing the old address — the Login Activity tab will
show their attempts as `unknown_email` failures. Tell them the new address
(the Audit Trail records both old and new). If they were signed out of every
device, they must sign in again with the new email and their existing
password.

**An admin locked themselves out.**
Another Super Admin clears it from this page. If literally nobody can get in,
run this against the database directly:

```sql
UPDATE users
   SET account_status = 'active', locked_until = NULL, failed_login_count = 0
 WHERE email = 'you@example.com';
```

## Who can use the Security Centre (since November 2026)

Clearance is the **Security Centre** row of Roles & permissions (day one: the Resident
Pastor, and Super Admins). "Super Admin" now means a role marked break-glass in Roles &
permissions — the guard that stops the last Super Admin being suspended counts those.
