<?php
// /includes/campaigns/bootstrap.php
//
// Email: personal addresses, consent, the domain's sending queue and hourly
// budget, block documents, campaigns and the automation engine (Embrace,
// Assimilation, Charis). Guide and contracts: docs/EMAIL.md.
//
// The only entry point. Pages, endpoints and crons require includes/db.php
// first (it owns the session, the PDO handle, .env and the account security
// gate), then this file.
//
// Map of the module:
//   util.php        Lagos clock, tokens, locks, table checks, settings
//   addresses.php   personal email: validate, capture, confirm, lookups
//   consent.php     the consent log and "may we send church news?"
//   suppression.php addresses that bounce or complained
//   budget.php      the domain's hourly sending budget, shared by everything
//   outbox.php      the queue, the worker, Message-IDs, the mailbox's lookup
//   doc.php         the block document and its validation (the sanitiser)
//   render.php      block document -> email-safe HTML and plain text
//   tracking.php    unsubscribe, clicks, opens
//   dsn.php         bounce and complaint reports
//   automation.php  the engine: rules, occasions, the send ledger, channels
//   journey.php     Embrace's first-timer journey
//   absence.php     Assimilation / Charis absence ladders (on the AWOL engine)
//   defaults.php    the default rules and their emails
//   coverage.php    who can be reached, and how
//
// The tree reaches production BEFORE db/migrate.php runs, so everything asks
// campaigns_ready() first and degrades: lookups answer null, nothing is queued,
// nothing throws into a page.

require_once __DIR__ . '/../site_url.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/addresses.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/suppression.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/outbox.php';
require_once __DIR__ . '/doc.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/dsn.php';
require_once __DIR__ . '/automation.php';
require_once __DIR__ . '/journey.php';
require_once __DIR__ . '/absence.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/coverage.php';
require_once __DIR__ . '/identity.php';
