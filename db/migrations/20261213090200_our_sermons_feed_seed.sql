-- /db/migrations/20261213090200_our_sermons_feed_seed.sql
--
-- Our sermons: the Lekki show's RSS feed (Spotify for Creators, RSS
-- distribution switched on 13 Nov 2026), so drafts start arriving on the
-- first cron tick after the deploy. Envision changes or removes it on
-- Manage sermons → Feed. Seeded only while no feed is set, so it never
-- brings back a feed Envision has replaced. last_checked_at stays NULL: the
-- next tick reads it.
-- Safe to run twice, portable to MySQL 8, MariaDB 10.11 and SQLite.

INSERT INTO our_sermon_feeds (url, title, is_active, items_seen, created_at, updated_at)
SELECT 'https://anchor.fm/s/116f1a844/podcast/rss', 'Household Of David Lekki Centre', 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
  FROM (SELECT 1 AS one) x
 WHERE NOT EXISTS (SELECT 1 FROM our_sermon_feeds);
