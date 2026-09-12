# Plan — Persian (فارسی) rewrite + artist-driven availability

Status: **Phase 0 done** (Persian/Jalali groundwork — `jalali.php`,
`lang.php` + `lang/fa.php`, RTL-safe sending in `telegram.php`,
`webhook.php` translated, `Asia/Tehran` timezone). Phases 1-4 (below) are
still just planned, not built.
Target: turn the current free-text booking bot into a Persian-language,
slot-based scheduling bot where the **artist publishes availability first**
and **clients pick from published slots**.

---

## 1. Goal in one paragraph

Today the client types any date and time they like, and the artist has to
approve or reject it — the bot has no idea what the artist's real schedule
looks like, and two clients can be approved for the same hour. The new
version inverts this: the artist first tells the bot which slots are free
(«زمان‌های آزاد»), clients then only ever see and tap those slots, and the
bot guarantees that a slot can be taken exactly once. The entire client- and
artist-facing surface becomes Persian, with the Jalali (Shamsi) calendar and
Persian digits.

---

## 2. What changes vs. the current bot

| Area | Now | After |
|---|---|---|
| Language | English | Persian only (all strings in `lang/fa.php`) |
| Calendar | Gregorian, `YYYY-MM-DD` typed by client | Jalali shown/typed (`۱۴۰۵/۰۶/۲۵`), Gregorian in DB |
| Digits | ASCII | Persian `۰-۹` on output, normalized on input |
| Timezone | `Europe/Berlin` | `Asia/Tehran` (no DST since 2022 → fixed +03:30) |
| Who picks the time | Client types it blindly | Artist publishes slots; client taps one |
| Double-booking | Possible | Impossible (atomic slot reservation) |
| Artist tooling | Approve/Reject buttons only | Add/list/delete slots, agenda, pending queue |
| Input style | Typed commands | Persistent reply keyboard + inline buttons |
| Code layout | All logic in `webhook.php` | Router + feature modules |

---

## 3. Localization strategy

This is the part most likely to be done badly, so it gets its own rules.

### 3.1 Jalali calendar

- **Storage stays Gregorian.** `DATE`/`TIME` columns keep Gregorian values.
  Convert only at the input/output boundary. Never store `۱۴۰۵/۰۶/۲۵` in a
  date column — sorting, indexing and `BETWEEN` all break.
- **Conversion: write our own `jalali.php`.** The Jalali↔Gregorian algorithm
  is ~40 lines of integer math. Rationale: the project has zero dependencies
  today (no Composer), and PHP's `intl` extension — the other zero-dependency
  option — is frequently missing on Iranian shared hosting. Rolling our own
  keeps the "upload 6 files by FTP" deployment story intact.
- Helpers to provide:
  - `jalaliToGregorian(int $jy, int $jm, int $jd): array`
  - `gregorianToJalali(int $gy, int $gm, int $gd): array`
  - `formatJalali(string $gregorianDate, string $style): string`
    → `'چهارشنبه ۲۵ شهریور ۱۴۰۵'` / `'۱۴۰۵/۰۶/۲۵'`
  - `parseJalaliDate(string $input): ?string` → Gregorian `Y-m-d` or null
- Month names: فروردین، اردیبهشت، خرداد، تیر، مرداد، شهریور، مهر، آبان، آذر، دی، بهمن، اسفند
- Weekday names: شنبه، یکشنبه، دوشنبه، سه‌شنبه، چهارشنبه، پنج‌شنبه، جمعه
- **The Persian week starts on شنبه (Saturday)** — matters for any week view
  or "next 7 days" button row.

### 3.2 Digits

- **Output:** `toPersianDigits()` applied to every number a user sees —
  dates, times, appointment IDs, counts. Do this once, in the send helper or
  the string formatter, not scattered per-message.
- **Input:** `normalizeDigits()` **before any parsing**. Must handle:
  - Persian digits `۰۱۲۳۴۵۶۷۸۹` (U+06F0–U+06F9)
  - Arabic-Indic digits `٠١٢٣٤٥٦٧٨٩` (U+0660–U+0669)
  - Arabic `ي`/`ك` → Persian `ی`/`ک`
  - Arabic comma `،` → `,` and Arabic/Persian decimal marks
  - Separators `/`, `-`, `.`, `ـ` in dates; `:` and `.` in times
  - ZWNJ (`\u{200C}`) and stray RTL marks stripped from numeric input
  This is not optional — an Iranian phone keyboard produces Persian digits
  by default, so unnormalized input will reject almost every real user.

### 3.3 RTL / bidi in Telegram

Telegram picks a paragraph's direction from its first strong character.
Persian text mixed with Latin/numbers will visibly scramble (times and IDs
jumping to the wrong end of the line) unless handled.

**Rule: every user-facing line must begin with a strong RTL character, or an
explicit RLM (`\u{200F}`).** Concretely:

- Lead lines with the Persian label, not the value: `⏰ ساعت: ۱۴:۳۰` — the
  emoji is direction-neutral, so the first strong char is Persian. Good.
- Any line that would start with a digit, `@username`, or Latin text gets a
  literal `\u{200F}` prefix.
- Never inline `@username` mid-sentence next to numbers; give it its own line.
- Test every template in the actual Telegram client (desktop **and** mobile
  render bidi slightly differently), not just by reading the source.

### 3.4 String catalog

All user-facing text lives in `lang/fa.php` returning `['key' => 'متن']`,
accessed via `t('key', ['name' => $x])`. Even though there's only one
language, this: keeps Persian out of the logic, makes wording/typo fixes a
one-file change, makes the RLM rules auditable in one place, and leaves the
door open for an English fallback later.

Sample entries:

```php
'welcome'         => 'سلام! به ربات نوبت‌دهی استودیو خوش آمدید. 🖋',
'btn_book'        => '📅 رزرو نوبت',
'btn_my_appts'    => '🗓 نوبت‌های من',
'btn_cancel'      => '❌ انصراف',
'no_slots'        => 'در حال حاضر زمان آزادی ثبت نشده است. لطفاً بعداً دوباره سر بزنید.',
'pick_day'        => 'لطفاً روز موردنظرتان را انتخاب کنید:',
'pick_time'       => 'ساعت موردنظرتان را انتخاب کنید:',
'ask_desc'        => "لطفاً تتوی موردنظرتان را کوتاه توضیح دهید (سبک، اندازه، محل روی بدن).",
'btn_skip_desc'   => '⏭ بدون توضیح',
'slot_taken'      => 'متأسفانه این نوبت همین‌الان توسط شخص دیگری رزرو شد. لطفاً زمان دیگری انتخاب کنید.',
'sent_to_artist'  => 'درخواست شما ارسال شد. به‌محض بررسی، نتیجه به شما اطلاع داده می‌شود.',
'status_pending'  => 'در انتظار تأیید',
'status_approved' => 'تأیید شده',
'status_rejected' => 'رد شده',
'status_cancelled'=> 'لغو شده',
```

Note the description step uses an inline **button** to skip rather than a
magic typed word — it sidesteps translating "skip" awkwardly and is a better
touch-first UX anyway.

---

## 4. Data model

### 4.1 New table: `availability_slots`

```sql
CREATE TABLE availability_slots (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    slot_date        DATE NOT NULL,
    slot_time        TIME NOT NULL,
    duration_minutes SMALLINT NOT NULL DEFAULT 90,
    status           ENUM('free','held','booked','blocked') NOT NULL DEFAULT 'free',
    appointment_id   INT DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                       ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_slot (slot_date, slot_time),
    INDEX idx_open (status, slot_date, slot_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`UNIQUE (slot_date, slot_time)` makes "artist accidentally adds the same hour
twice" a no-op instead of a duplicate button in the client's list.

### 4.2 Changes to `appointments`

```sql
ALTER TABLE appointments
    ADD COLUMN slot_id INT DEFAULT NULL AFTER id,
    ADD COLUMN cancelled_by ENUM('client','artist') DEFAULT NULL,
    ADD CONSTRAINT fk_appt_slot FOREIGN KEY (slot_id)
        REFERENCES availability_slots(id) ON DELETE SET NULL;
```

**Keep `appointment_date` / `appointment_time` on the appointment row** even
though the slot has them. They are a *snapshot*: if the artist later deletes
a slot, the historical appointment must not lose the date it was booked for.
`slot_id` is the live link; the denormalized columns are the history.

### 4.3 `bot_states`

Extend `step` values and reuse the temp columns:

| Step | Meaning |
|---|---|
| `idle` | nothing in progress |
| `choosing_day` / `choosing_time` | client picking a slot |
| `awaiting_desc` | client describing the tattoo |
| `awaiting_confirm` | client on the summary screen |
| `artist_slot_date` | artist entering a date for new slots |
| `artist_slot_times` | artist entering times for that date |
| `artist_slot_confirm` | artist reviewing the batch before insert |

Add `temp_slot_id INT NULL` so the chosen slot survives the description step.

---

## 5. Slot lifecycle & concurrency

```
                 client confirms            artist approves
   free  ──────────────────────────►  held ──────────────────────►  booked
     ▲                                  │                              │
     │        artist rejects /          │      client cancels          │
     └──────── client cancels ──────────┘◄─────────────────────────────┘
     │
     └── artist blocks the slot ──► blocked   (artist deletes ──► row removed)
```

**The reservation must be an atomic compare-and-swap, not read-then-write:**

```sql
UPDATE availability_slots
   SET status = 'held', updated_at = NOW()
 WHERE id = :id AND status = 'free';
```

Then check `rowCount() === 1`. If it's 0, someone else won the race → show
`slot_taken` and re-render that day's remaining times. Wrap the CAS and the
`INSERT INTO appointments` in a single transaction so a crash between them
can't strand a held slot.

**No hold at selection time** — only at confirm. Holding the moment a user
taps a time would let an abandoned conversation lock a slot indefinitely, and
would need an expiry cron to clean up. The cost of not holding is a rare
"sorry, just taken" at the final step, which is a fair trade for v1.

If approval is required, the slot sits in `held` until the artist decides.
Reject → back to `free` (and the day becomes bookable again automatically).

---

## 6. Flows

### 6.1 Artist

Persistent reply keyboard (Iranian users expect this far more than typed
slash commands):

```
➕ افزودن زمان آزاد   |   🗓 زمان‌های من
⏳ درخواست‌های در انتظار |   📋 برنامه امروز
```

**Adding slots** — must support bulk entry; no artist will add 20 slots one
at a time:

1. Bot asks for the date. Offers quick inline buttons (امروز / فردا / next 7
   days, each labeled `چهارشنبه ۲۵ شهریور`), or accepts a typed Jalali date.
2. Bot asks for times, accepting either form:
   - a list: `۱۰:۰۰، ۱۲:۰۰، ۱۴:۳۰`
   - a range with step: `۱۰:۰۰ تا ۱۸:۰۰ هر ۹۰ دقیقه`
3. Bot shows the parsed batch for confirmation, flags any that already exist,
   then inserts.

**`زمان‌های من`** lists upcoming slots grouped by day, each with its status
badge and a ❌ button. Deleting a `free` slot is silent; attempting to delete
a `booked` one must warn and offer to cancel + notify the client instead.

### 6.2 Client

1. `/start` → welcome + reply keyboard `📅 رزرو نوبت | 🗓 نوبت‌های من`.
2. **Pick a day** — inline buttons for days that have ≥1 free slot, showing
   the count: `چهارشنبه ۲۵ شهریور (۳ نوبت)`. Paginate beyond ~8 days.
3. **Pick a time** — free times for that day: `۱۰:۰۰ | ۱۲:۰۰ | ۱۴:۳۰`.
4. **Describe the tattoo**, or tap `⏭ بدون توضیح`.
5. **Summary + `✅ تأیید` / `❌ انصراف`.**
6. Confirm → atomic reserve → artist notified (or instant confirmation if
   approval is turned off).

Clients never see other people's bookings — taken slots simply aren't listed.

**Callback data budget:** Telegram caps `callback_data` at 64 bytes. Use
compact prefixes — `d:2026-09-16`, `s:1234`, `ok:1234`, `no:1234` — rather
than anything verbose or JSON.

### 6.3 Client self-cancellation (new)

Once slots are real, clients cancelling needs to return inventory. From
`🗓 نوبت‌های من`, an upcoming approved appointment gets a `لغو نوبت` button:
sets the appointment to `cancelled`, returns the slot to `free`, notifies the
artist. Gate it with a configurable cutoff (e.g. no self-cancel within 24h —
`cancel_cutoff_hours`), past which the client is told to contact the studio.

---

## 7. File structure

`webhook.php` is 336 lines today and would roughly triple. Split it:

| File | Purpose |
|---|---|
| `webhook.php` | Thin router: verify secret, decode update, dispatch |
| `booking.php` | Client flow handlers |
| `artist.php` | Artist/admin flow handlers |
| `slots.php` | Slot CRUD + the atomic reservation primitive |
| `state.php` | Conversation state (extended) |
| `telegram.php` | API wrapper (+ reply-keyboard helper, RLM-safe send) |
| `jalali.php` | Jalali↔Gregorian, Persian digits, input normalization |
| `lang/fa.php` | Every user-facing string |
| `db.php` | Unchanged |
| `migrations/001_slots.sql` | Additive migration for existing installs |

New config keys: `require_approval` (bool), `slot_duration_minutes`,
`booking_horizon_days`, `cancel_cutoff_hours`, `timezone => 'Asia/Tehran'`.

---

## 8. Phases

Each phase should be independently shippable and testable.

**Phase 0 — Persian groundwork.** `jalali.php`, `lang/fa.php`, digit
helpers, input normalization, RLM rules, `Asia/Tehran`. Translate the
*existing* flow with no feature changes. Ships a working Persian version of
today's bot — de-risks all localization before touching scheduling.

**Phase 1 — Availability.** Slots schema + migration, artist reply keyboard,
add/list/delete slots, bulk entry parsing. Client side untouched.

**Phase 2 — Slot-based booking.** Replace free-text date/time with day/time
pickers, atomic reservation, `slot_taken` handling. This is the phase where
the old `handleDateInput`/`handleTimeInput` client code is deleted.

**Phase 3 — Decisions & cancellation.** Approval rewired to slots (reject →
slot freed), client self-cancel, artist cancel-with-notify.

**Phase 4 — Nice-to-haves.** Agenda views (`برنامه امروز` / هفته), 24h
reminders via cron, weekly recurring templates, simple stats.

---

## 9. Testing notes

- **There is still no PHP runtime on this machine** (`php` not on PATH), so
  `jalali.php` has not been executed as PHP. What *was* done: the exact
  conversion algorithm and the date/time parsing-and-validation logic were
  prototyped in Python (which is installed) and checked against a
  day-by-day round-trip over 1950-2050 (36,890 days, 0 mismatches), known
  reference dates (Nowruz for 1403-1405, the 1979 revolution date, etc.),
  leap-year cases (1403 and 1408 leap, 1404/1405 not), and Esfand's
  29-vs-30-day boundary, before being ported to PHP line-for-line. That's
  strong evidence the *logic* is right, but it is not the same as running
  the shipped PHP file — install PHP 8.1+ (or use Docker) before deploying,
  and at minimum smoke-test `jalali.php`'s functions directly.
- The concurrency CAS (Phase 2) needs a deliberate test: fire two confirms
  at the same slot and assert exactly one wins.
- Verify every Persian template visually in Telegram desktop *and* mobile —
  this has not been done yet since Phase 0 hasn't been deployed to a real
  bot/chat.

## 10. Security note

The repo is **now public**. `config.php` is gitignored and has never been
committed (verified), and it must stay that way — every new config key goes
into `config.example.php` with a placeholder. The live bot token that was
exposed earlier in this session should still be rotated via @BotFather.

---

## 11. Open decisions

These change the scope, so they're worth settling before Phase 1.

1. **Keep the approval step?** Now that the artist has already declared the
   slot free, approval is arguably redundant friction. *Recommendation:* keep
   it, but behind `require_approval` in config (default on) so it can be
   switched to instant booking without a code change.
2. **Slot duration** — fixed 90 min for every slot, or per-slot, or driven by
   tattoo size? *Recommendation:* per-slot with a config default; it costs one
   extra column and no extra UI if the default is usually right.
3. **Deposit / prepayment?** Common for tattoo studios in Iran (card-to-card
   or a gateway like Zarinpal). Out of scope for v1, but it changes the slot
   lifecycle (`held` until paid), so decide now whether to design for it.
4. **Phone number collection?** Telegram's `request_contact` button makes this
   one tap, and most studios want it. Cheap to add in Phase 2 if wanted.
5. **Multiple artists?** Current design assumes one. Supporting more means an
   `artists` table and an `artist_id` on slots — much cheaper to design in now
   than to retrofit.
6. **Booking horizon** — how far ahead can clients book? (Suggest 30 days.)
7. **Should the plan doc itself be in Persian?** Written in English here on
   the assumption it's a dev-facing document; easy to translate if the
   audience is different.
