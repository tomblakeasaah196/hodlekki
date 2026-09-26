-- 20260926120100_reach_campaigns.sql
-- Fresh reach_campaigns table. Each campaign has a URL-safe slug used by
-- the public capture page (/reach.php?c=<slug>), a payload tier that
-- decides which optional fields the public form asks for, and share
-- metadata (meta_description, share_scripture) so WhatsApp/link previews
-- look right.

CREATE TABLE IF NOT EXISTS reach_campaigns (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug              VARCHAR(120) NOT NULL,
    title             VARCHAR(200) NOT NULL,
    campaign_type     VARCHAR(60)  NOT NULL DEFAULT 'Saturday_Evangelism',
    campaign_date     DATE         NULL,
    start_time        TIME         NULL,
    end_time          TIME         NULL,
    location          VARCHAR(255) NULL,
    meta_description  TEXT         NULL,
    share_scripture   TEXT         NULL,
    payload_tier      ENUM('Rapid','Standard','Rich') NOT NULL DEFAULT 'Rich',
    status            ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
    created_by        INT UNSIGNED NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_reach_campaigns_slug (slug),
    KEY idx_reach_campaigns_status (status),
    KEY idx_reach_campaigns_date (campaign_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
