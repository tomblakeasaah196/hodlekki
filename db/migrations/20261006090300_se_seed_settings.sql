-- 20261006090300_se_seed_settings.sql
-- Default module settings. Existing values are never overwritten.

INSERT INTO se_settings (setting_key, setting_value) VALUES
    ('envision_department_id', '3'),
    ('default_brand_primary', '#1D356A'),
    ('default_brand_secondary', '#D11920'),
    ('default_theme_preset', 'marquee'),
    ('retention_months_guest', '24'),
    ('ai_user_hourly_limit', '30'),
    ('ai_event_daily_limit', '300'),
    ('source_codes_json', '{"wa":"WhatsApp","ig":"Instagram","fb":"Facebook","tt":"TikTok","x":"X","flyer":"Flyer","poster":"Poster","sms":"SMS","pulpit":"Church announcement","qr":"QR code","email":"Email"}')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
