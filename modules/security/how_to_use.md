# Security Centre — how to use

**Who can open it:** Super Admin and the Resident Pastor.
**Where:** sidebar → *Security & Stewardship* → **Security Centre**
(`/modules/security/index.php`) — or search "Security" in the Ctrl/Cmd+K
palette (each tab — Accounts, Live Sessions, Login Activity, Audit Trail — is
searchable there too).

Clearance is checked against the live `user_roles` table, not
`$_SESSION['active_role']` — switching your active role on the profile screen
does not open or close this door.

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
| **Force change at next login** | They land on `/auth/change_password.php` and cannot reach any other page until they set a new password. |
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
- **Live Sessions** — every browser signed in over the last 7 days, with the
  device, IP and last activity. *Sign out* kills that one device.
- **Login Activity** — successes and failures, with the failure reason
  (`bad_password`, `account_suspended`, `account_locked`, `unknown_email`…).
  Filter to failures only when you are investigating.
- **Audit Trail** — who did what to whom, when, and from which IP. Every
  privileged action on this page writes a row here. Nothing deletes rows.

---

## 4. Resetting a password, step by step

1. **Accounts** tab → search for the member → **Manage**.
2. Scroll to **Reset password**.
3. Either type a password or press **Suggest strong password** (it fills both
   boxes with a 14-character random password).
4. Leave **Require them to change it at next sign-in** ticked. This is the
   whole point: you know the temporary password, they must replace it.
5. Press **Set new password** and confirm.
6. **Copy the password before you close the dialog — it is never shown again.**
   Read it to the member over the phone or in person, not over WhatsApp.

Everything they were signed into is signed out immediately.

---

## 5. Changing a member's sign-in email, step by step

Use this when a member keeps forgetting their system-generated sign-in email,
or finds it too long — they sign in with `grace@hodlc.com`, not their personal
Gmail/Yahoo address. It also **sets** a sign-in email for members who were
created without one (the card reads "Set sign-in email" and shows a warning
that they cannot sign in yet). The personal email lives on their profile and
is never touched here.

1. **Accounts** tab → search for the member → **Manage**.
2. Scroll to **Change sign-in email**. Their current sign-in email (and
   personal email, if they have one) is shown at the top of the card.
3. Type what they want *before* the `@` — the `@hodlc.com` part is fixed, so
   it cannot be mistyped. Type it again in the confirm box.
4. If the account may be compromised, or you simply want them to sign in
   fresh with the new address, tick **Sign them out of every device now**.
5. Press **Change sign-in email** and confirm.
6. **Tell the member the new address** — the old one stops working the moment
   you press the button. Their password does not change.

The new address must end in `@hodlc.com` and must not already belong to
another member. Every change is written to the audit trail
(`login_email_changed`, showing the old and new address) and the member gets
an in-app notification.

### Filling every missing email at once

If several members have no sign-in email at all, don't do them one by one:

1. **Accounts** tab → **Generate missing emails** (toolbar button).
2. Confirm. The system generates a unique `@hodlc.com` address for **every**
   account without one, exactly the way new members' addresses are generated
   (shortest name parts, e.g. `oluchi.chiamaka@hodlc.com`, with a number
   appended if that address is already taken).
3. Each member generated for gets an in-app notification telling them their
   new sign-in email. One `login_email_generated` audit row is written per
   member.

Accounts whose names contain no letters or digits (rare) are skipped and
listed in the message — set those by hand via *Manage*. To see who is missing
an email first, filter the roster by **No sign-in email**.

---

## 6. Automatic protections (no admin action needed)

- **5 consecutive failed sign-ins** locks the account for **15 minutes** and
  writes an `account_auto_locked` audit row. Clear it early with *Clear lockout*.
- **Password policy** on every entry point (self-service change, admin reset,
  first-time setup): at least 10 characters, one uppercase, one lowercase, one
  number, and it may not contain the member's own name or email or an obvious
  word like `password`.
- **Session fixation** is blocked — the PHP session id is rotated on login.
- **`/auth/setup_password.php` is first-time only.** It used to let anyone who
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
- press **Sign out all other devices**.

---

## 8. Troubleshooting

**"Security tables are not installed yet."**
The migration has not run. On the server:
`php db/migrate.php` (or trigger a deploy — `bin/deploy.sh` runs it).

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
