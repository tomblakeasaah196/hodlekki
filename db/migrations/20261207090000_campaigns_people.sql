-- /db/migrations/20261207090000_campaigns_people.sql
--
-- Campaigns (PR 5): who a campaign reached, and how. One row per person when a
-- campaign is expanded (includes/campaigns/campaigns.php): the channel
-- (email / sms / none), why nobody was reached, and the email or text it
-- became. UNIQUE (campaign_id, user_id): a person is in a campaign once, even
-- if an expansion is retried. The report reads it with email_outbox and
-- sms_queue. Forward-only; portable to MySQL 8 and MariaDB 10.11.

CREATE TABLE IF NOT EXISTS email_campaign_people (
    id INT NOT NULL AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    user_id INT NOT NULL,
    channel VARCHAR(8) NOT NULL,
    reason VARCHAR(30) NULL DEFAULT NULL,
    outbox_id INT NULL DEFAULT NULL,
    sms_queue_id INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_campaign_people (campaign_id, user_id),
    KEY ix_email_campaign_people_channel (campaign_id, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
