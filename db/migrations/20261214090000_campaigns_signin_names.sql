-- /db/migrations/20261214090000_campaigns_signin_names.sql
--
-- The church-issued @hodlc.com sign-in names die out (owner's decision,
-- 13 Nov 2026). When someone confirms a personal email it takes the name's
-- place in users.email (email_sync_users_email(), includes/campaigns/
-- addresses.php). The old name is kept here only so that typing it on the
-- sign-in page still says where the person signs in now.
--
-- People who confirmed an email before this deploy are swapped by
-- db/backfill_signin_names.php (bin/deploy.sh runs it after the migrations),
-- through the same function, so both paths can never disagree.

CREATE TABLE IF NOT EXISTS auth_retired_signin_names (
    name VARCHAR(190) NOT NULL,
    user_id INT NOT NULL,
    retired_at DATETIME NOT NULL,
    PRIMARY KEY (name),
    KEY ix_auth_retired_signin_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
