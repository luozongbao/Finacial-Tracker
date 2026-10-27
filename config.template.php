<?php
/**
 * Configuration template — the tracked twin of config.php.
 *
 * config.php is git-ignored and is written by install.php at install time.
 * This file declares the SAME constant set, with placeholder values. Any constant
 * added or removed here must be added or removed in config.php in the same change.
 *
 * Value types matter:
 *   - ALLOW_REGISTRATION is a real PHP boolean (true / false), never the strings
 *     'true' / 'false'. A string comparison fails OPEN: 'false' === false is false,
 *     so registration would stay open when the operator meant to close it.
 *   - SMTP_PORT is an integer, not a quoted number.
 *   - Every other value is a string.
 *
 * The constant is spelled with underscores. A hyphen is not legal in a PHP constant
 * name, so 'ALLOW-REGISTRATION' could only be read back via constant('...').
 */

declare(strict_types=1);

// ---- Database -------------------------------------------------------------
const DB_HOST = 'localhost';
const DB_NAME = 'financial_tracker';
const DB_USER = 'your_db_user';
const DB_PASS = 'your_db_password';

// ---- SMTP (used by the account-verification and password-reset mailer) ----
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USER = 'your_email@gmail.com';
const SMTP_PASS = 'your_email_password';

// Sender identity. SMTP_USER does not always provide one, so the From: address
// and its display name are separate constants. Written by install.php — see
// docs/issues/issue-010.md.
const MAIL_FROM = 'no-reply@example.com';
const MAIL_FROM_NAME = 'Financial Tracker';

// ---- Application ----------------------------------------------------------

// Public registration. true = the register page is open; false = it is closed
// on BOTH the front end (403, no form) and the back end (the POST handler
// rejects the request).
const ALLOW_REGISTRATION = true;

// Week-start rule for period reports, as a weekday number: 1 = Monday ... 7 = Sunday.
// Keep it in exactly one place so the reports and the UI always agree.
const WEEK_START = 1;

// Transaction dates are stored as DATE in UTC.
const TIMEZONE = 'UTC';
