-- 20261206090000_perm_ai_for_every_role.sql
--
-- AI in Mail for everyone who can reply (owner, 12 Nov 2026: "every mailbox
-- should use it", for every role that can reply). Every role gets the
-- church-wide `mail.ai` ability (view). It only gives the AI buttons on a
-- mailbox the person can already open: who reads or replies from a mailbox
-- is still that mailbox's own grants (docs/MAIL.md §7e), and a mail admin
-- still switches AI on per mailbox. Change it later in Roles & permissions.
-- Safe to run twice (NOT EXISTS), portable to MySQL 8 and MariaDB 10.11.

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'mail.ai', 1, 0, 0, 0, 'church', NULL
  FROM roles r
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'mail.ai');
