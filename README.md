# ربات تلگرام نوبت‌دهی تتو (Tattoo Appointment Telegram Bot)

A webhook-based PHP Telegram bot, in **Persian**, that lets clients request
a tattoo appointment (date, time, description), sends the request to the
tattoo artist for approval, and stores it in MySQL once a decision is made.
Dates are shown and typed in the **Jalali (Shamsi)** calendar with Persian
digits; the database still stores plain Gregorian dates underneath.

See `PLAN.md` for the roadmap toward artist-published availability slots
(the current version is "Phase 0": Persian/Jalali on top of the original
free-text booking flow, with no scheduling changes yet).

## How it works

1. Client sends `/book` → bot asks for date → time → description, step by step.
2. Client confirms the summary (inline **تأیید / انصراف** buttons).
3. The request is saved in `appointments` with status `pending`, and the
   **artist** is notified in a separate chat with **تأیید / رد** buttons.
4. When the artist taps a button, the row is updated to `approved` or
   `rejected`, and the client is notified automatically.
5. Clients can check `/myappointments` at any time.

Conversation progress (which step each user is on) is kept in the
`bot_states` table, since a webhook has no memory between requests.

## Files

| File                  | Purpose                                              |
|------------------------|-------------------------------------------------------|
| `webhook.php`          | Entry point Telegram calls — all bot logic lives here |
| `telegram.php`         | Thin wrapper around the Telegram Bot API (+ RTL-safe send) |
| `db.php`                | PDO/MySQL connection helper                          |
| `state.php`             | Per-user conversation state (get/save/reset)          |
| `jalali.php`            | Jalali↔Gregorian conversion, Persian digits, RTL line safety |
| `lang.php`              | Loads `lang/fa.php` and provides the `t()` string helper |
| `lang/fa.php`           | Every user-facing Persian string                     |
| `schema.sql`            | Creates `appointments` and `bot_states` tables (dates stored in Gregorian) |
| `config.example.php`    | Copy to `config.php` and fill in your values          |
| `.htaccess`             | Blocks direct web access to everything except `webhook.php` |
| `PLAN.md`               | Roadmap: artist-published availability slots, phased |

## Requirements

- PHP 8.1+ with the `pdo_mysql`, `curl`, and `mbstring` extensions (`mbstring`
  is needed for correct Persian text handling; it's near-universal on shared
  hosting, unlike `intl`, which is why the Jalali conversion is hand-rolled
  instead of depending on it — see `PLAN.md` section 3.1)
- MySQL 5.7+/MariaDB 10.3+
- A public HTTPS URL (Telegram webhooks require HTTPS — a shared host,
  VPS with a reverse proxy, or a tunnel like `ngrok`/`cloudflared` for testing)

## Setup

1. **Create the bot.** Message [@BotFather](https://t.me/BotFather) on
   Telegram, run `/newbot`, and copy the token it gives you.

2. **Find the artist's chat ID.** Have the artist message
   [@userinfobot](https://t.me/userinfobot) — it replies with their numeric
   Telegram ID. (Alternatively, temporarily log
   `$message['from']['id']` in `webhook.php` when they send `/start`.)

3. **Create the database:**
   ```bash
   mysql -u root -p -e "CREATE DATABASE tattoo_bot CHARACTER SET utf8mb4;"
   mysql -u root -p tattoo_bot < schema.sql
   ```

4. **Configure the bot:**
   ```bash
   cp config.example.php config.php
   ```
   Edit `config.php` and fill in `bot_token`, `artist_chat_id`, and your
   `db` credentials. **Never commit `config.php`** — add it to `.gitignore`.

5. **Upload** `webhook.php`, `telegram.php`, `db.php`, `state.php`,
   `jalali.php`, `lang.php`, the `lang/` folder (with `fa.php` inside it),
   `config.php`, and **`.htaccess`** to your server, all in the same
   directory structure. `.htaccess` is a hidden file — if your FTP client or
   File Manager doesn't show it by default, look for a "show hidden files"
   toggle. It blocks direct browser access to every file except
   `webhook.php`, so `config.php` and the rest can't be opened or
   downloaded by visiting their URL directly. (Verified: everything but
   `webhook.php` returns 403 Forbidden when requested directly.)

6. **Register the webhook** with Telegram (replace the placeholders):
   ```bash
   curl "https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://yourdomain.com/path/webhook.php"
   ```
   You should get back `{"ok":true,"result":true,...}`.

   To also verify the request really came from Telegram, generate a random
   secret string, put it in `webhook_secret` in `config.php`, and pass it
   when setting the webhook:
   ```bash
   curl "https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://yourdomain.com/path/webhook.php&secret_token=<YOUR_SECRET>"
   ```

7. **Test it.** Message your bot `/start`, then `/book`, and walk through
   the flow (the bot will ask for a date like `۱۴۰۵/۰۶/۲۵` and a time like
   `۱۴:۳۰` — Latin digits work too, they're normalized automatically). The
   artist's chat should get a request with تأیید/رد buttons once you confirm.

## Bot commands

- `/start` — welcome message
- `/book` — start a new appointment request (date in Jalali, e.g. `1405/06/25`)
- `/cancel` — abort whatever step you're on
- `/myappointments` — list your last 10 requests and their status

## Notes & possible extensions

- Dates/times aren't checked against existing bookings for double-booking —
  see `PLAN.md` for the planned move to artist-published availability slots,
  which closes this gap with an atomic reservation instead of a query added
  here.
- `description` is optional — the client can send "رد شدن" (or "skip").
- Only the chat ID in `artist_chat_id` can approve/reject — a second artist
  would need either a second bot or a small `artists` table instead of a
  single ID in config.
- All appointments (any status) stay in the table for history; nothing is
  deleted, so you can review rejected/past requests later.
- All dates are stored in the database as plain Gregorian `DATE`/`TIME`
  values; Jalali conversion happens only when displaying to, or parsing
  input from, the user. Never edit `appointment_date`/`appointment_time`
  by hand with a Jalali value — see `jalali.php` for the conversion helpers.
