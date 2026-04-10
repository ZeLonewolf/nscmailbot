# nscmailbot

Small PHP utilities for **Newport Ski Club** booking notification emails on Bluehost/cPanel: parse one raw message into a structured event, append a canonical activity line to a log, and (separately) email the log to the reservationist and roll it over.

Requires **PHP 7.4+** (PHP 8.x recommended). No Composer or database.

## Layout

| Path | Role |
|------|------|
| [`src/EmlBodyExtractor.php`](src/EmlBodyExtractor.php) | Full `.eml` / MIME message → envelope headers + best `text/plain` or `text/html` part (QP + multipart) |
| [`src/RawEmailEnvelope.php`](src/RawEmailEnvelope.php) | Inbound-to-bookings vs club-sender check; merges envelope into parsed events |
| [`src/EmailNormalizer.php`](src/EmailNormalizer.php) | HTML-ish body → normalized plain text |
| [`src/BookingParser.php`](src/BookingParser.php) | Raw body string → structured event array |
| [`src/BookingFormatter.php`](src/BookingFormatter.php) | Event → activity log line + digest line |
| [`src/LogWriter.php`](src/LogWriter.php) | Append one UTF-8 line (newlines stripped) |
| [`bin/process_booking_email.php`](bin/process_booking_email.php) | CLI / pipe entrypoint |
| [`bin/notify_reservationist.php`](bin/notify_reservationist.php) | Mail log contents, then timestamped archive + empty log |
| [`sample-emails/`](sample-emails/) | Saved `.eml` files (multipart + quoted-printable) used by tests |
| [`tests/run_tests.php`](tests/run_tests.php) | Standalone assertions (no PHPUnit) |

## Parse one email (CLI)

From a file:

```bash
php bin/process_booking_email.php /path/to/raw_email.txt
```

From stdin:

```bash
php bin/process_booking_email.php - < /path/to/raw_email.txt
```

Append the activity line to a log (directory is created if possible). On the server, `USER` is your cPanel username (`/home/USER` is your home directory):

```bash
cd ~/nscmailbot
php bin/process_booking_email.php --log ~/logs/booking_activity.log /path/to/raw_email.txt
```

Machine-readable JSON (includes `event`, `activity_line`, `digest_line`, `processed_at`):

```bash
php bin/process_booking_email.php --json --pretty "sample-emails/Cancelled Booking from 3 6 2026 to 3 7 2026.eml"
```

### Output formats

**Activity log line** (single line; missing reference/name/datetimes appear as `-`):

```text
{processed_at_iso} | {event_type} | {booking_reference} | {contact_name} | {check_in_iso} -> {check_out_iso} | members={members} children={children} guests={guests} unknown={unknown}
```

**Digest line** (human-oriented; zero count categories omitted unless all zero → `0 occupants parsed`):

```text
{event_type}: {booking_reference} {contact_name}, {short_date_range}, {count_summary}
```

## Tests

```bash
php tests/run_tests.php
```

Tests load the saved messages under [`sample-emails/`](sample-emails/) (cancellation, tentative booking, edited-booking notice, and a non-template wait-list message), plus a few inline cases for `UNKNOWN` logging and `LogWriter`.

### Event types

| `event_type` | When |
|--------------|------|
| `BOOKED` | “CONFIRMED booking” template |
| `TENTATIVE` | “TENTATIVE booking” template |
| `CANCELLED` | Cancellation template |
| `EDITED` | Short “Booking NP… was edited by …” notice (no bunk table) |
| `UNKNOWN` | Anything else (e.g. freeform mail) |

**UNKNOWN logging:** When the input is a full MIME message, envelope headers are merged into the event (`envelope_from`, `envelope_to`, `envelope_subject`, etc.). If `event_type` is `UNKNOWN` **and** the message is **to** `bookings@newportskiclub.org` (from `To:` or `Delivered-To:`) **and** the sender is **not** `bookings@newportskiclub.org` (parsed from `From:`, with `Return-Path` used only when `From` has no address), the activity and digest lines are shortened to sender + subject only:

- Activity: `{processed_at} | UNKNOWN | from=… | subject=…`
- Digest: `UNKNOWN: …, …`

Other `UNKNOWN` cases (bare body with no headers, or mail clearly from the club mailbox) keep the normal activity line with `-` placeholders and zero counts.

The CLI runs [`EmlBodyExtractor::extractEnvelope`](src/EmlBodyExtractor.php) and [`EmlBodyExtractor::extractBodyForParser`](src/EmlBodyExtractor.php) so piping a full `.eml` or raw MIME message works the same as piping a bare body, with routing metadata available for logging.

If `php` is not installed locally, for example:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/run_tests.php
```

## Notify reservationist (cron)

On Bluehost, keep the project at **`~/nscmailbot`** (i.e. `nscmailbot` in your home directory, full path **`/home/USERNAME/nscmailbot`**). Cron and the pipe below need **absolute** paths; replace `USER` with your cPanel username where shown.

Set environment variables (e.g. in a wrapper script referenced from cron):

| Variable | Required | Purpose |
|----------|----------|---------|
| `NSC_ACTIVITY_LOG` | Yes | Path to the append-only activity log (same file `--log` uses) |
| `NSC_MAIL_TO` | Yes | Reservationist address |
| `NSC_MAIL_FROM` | No | `From:` header (default `bookings@newportskiclub.org`) |
| `NSC_MAIL_SUBJECT` | No | Subject (default `New Newport Ski Club booking activity`) |

Run:

```bash
cd ~/nscmailbot
export NSC_ACTIVITY_LOG=/home/USER/logs/booking_activity.log
export NSC_MAIL_TO=reservationist@example.org
php bin/notify_reservationist.php
```

Behavior:

- If the log file is missing, unreadable, or empty (whitespace only): exits **0** and sends nothing.
- If `mail()` succeeds: renames the log to `basename_YYYYMMDDTHHMMSSZ.log` in the **same directory**, then creates a new empty file at the original path.
- If `mail()` fails: exits **non-zero** and does **not** roll the log.

**Note:** `mail()` depends on host configuration. If delivery fails, fix MTA/auth on the host or replace the script body with SMTP later.

## cPanel pipe-to-program (Bluehost-style)

Bluehost’s form asks for a path **relative to your home directory** and tells you **not** to prefix `php` or `/usr/bin/php`—the script must be **executable** and start with a **shebang** (`#!`) so the OS runs PHP for you.

1. Upload the tree so it lives at **`~/nscmailbot`** (folder `nscmailbot` under your home—same layout as this repo).
2. Create **`~/logs/`** and ensure it is writable by the user that runs the mail pipe.
3. **Shebang:** The first line of [`bin/process_booking_email.php`](bin/process_booking_email.php) is `#!/usr/bin/php`. If your account uses a different PHP (MultiPHP / EasyApache), fix that line over SSH to match your real binary, e.g. `which php` or the path cPanel shows (sometimes under `/opt/cpanel/ea-php…`).
4. **Executable bit (required for pipe):**

   ```bash
   chmod +x ~/nscmailbot/bin/process_booking_email.php
   chmod +x ~/nscmailbot/bin/notify_reservationist.php
   ```

5. In **cPanel → Forwarders → Pipe to a Program**, enter **only** the path **relative to home** (no leading `|`, no `php`—unless your screen’s example shows a pipe character; follow what cPanel displays):

   ```text
   nscmailbot/bin/process_booking_email.php
   ```

6. **Logging:** With no `--log` argument, a **piped** invocation (stdin not a terminal) appends to **`$HOME/logs/booking_activity.log`** when `HOME` (or POSIX user dir) is available. You can still override with **`NSC_ACTIVITY_LOG`** in the environment, or **`--log /path/to/file`** when calling PHP yourself.

7. Schedule cron for the reservationist notifier (e.g. daily), exporting **`NSC_ACTIVITY_LOG`** (and other `NSC_*` vars) to the **same** log path you expect from the pipe.

## Integration notes

- **Pipe input:** The handler accepts a **full RFC822 message** on stdin (headers + body); [`EmlBodyExtractor`](src/EmlBodyExtractor.php) picks the body part. If anything looks wrong on first live mail, capture stdin to a file once and inspect it.
