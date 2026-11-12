-- /db/migrations/20261210090000_integrations_google.sql
-- Trainings, PR 3 (docs/INTEGRATIONS.md): the settings of outside services the ERP connects to, first Google
-- (Calendar events with a Meet link, Meet attendance). One row per setting. Secrets (the refresh token, the cached
-- access token) are stored encrypted with INTEGRATIONS_VAULT_KEY ("v1:<key id>:<base64>"), never in the clear.
-- The client ID and secret stay in .env (GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET), not here.
-- Idempotent: safe to apply twice.

CREATE TABLE IF NOT EXISTS integration_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value TEXT         NULL,
    updated_by    INT          NULL,
    updated_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
