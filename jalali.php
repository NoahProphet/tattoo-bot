<?php

/**
 * Persian (Jalali/Shamsi) calendar conversion, Persian digit handling, and
 * RTL line-safety helpers.
 *
 * The Gregorian<->Jalali math below is a PHP port of the algorithm used by
 * jalaali-js (Behrang Noruzi Niya, MIT), itself based on Kazimierz
 * Borkowski's "Calendrical Calculations" paper. It's accurate across
 * Jalali years -61..3177 and was checked in this project against a
 * day-by-day round-trip over 1950-2050 (36,890 days, zero mismatches)
 * before being ported here -- see PLAN.md section 3.1 for why we roll our
 * own instead of depending on the intl extension.
 *
 * Storage rule: the database always keeps Gregorian dates. Every function
 * here is a boundary conversion for display (Gregorian -> Jalali) or for
 * parsing user input (Jalali -> Gregorian) -- never for storage.
 */

const JALALI_MONTH_NAMES = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
];

// Keyed by PHP's date('N') ISO-8601 weekday number (1=Monday .. 7=Sunday).
const JALALI_WEEKDAY_NAMES = [
    1 => 'دوشنبه',
    2 => 'سه‌شنبه',
    3 => 'چهارشنبه',
    4 => 'پنج‌شنبه',
    5 => 'جمعه',
    6 => 'شنبه',
    7 => 'یکشنبه',
];

const JALALI_BREAKS = [
    -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
    1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
];

// ---------------------------------------------------------------------------
// Core conversion math (internal)
// ---------------------------------------------------------------------------

function _jalaliMod(int $a, int $b): int
{
    // PHP's % already truncates toward zero (sign follows the dividend),
    // which is exactly the "mod" used throughout the reference algorithm.
    return $a % $b;
}

function _jalCal(int $jy): array
{
    $breaks = JALALI_BREAKS;
    $bl = count($breaks);
    $gy = $jy + 621;
    $leapJ = -14;
    $jp = $breaks[0];

    if ($jy < $jp || $jy >= $breaks[$bl - 1]) {
        throw new InvalidArgumentException("Jalali year {$jy} is out of the supported range.");
    }

    $jump = 0;
    for ($i = 1; $i < $bl; $i++) {
        $jm = $breaks[$i];
        $jump = $jm - $jp;
        if ($jy < $jm) {
            break;
        }
        $leapJ = $leapJ + intdiv($jump, 33) * 8 + intdiv(_jalaliMod($jump, 33), 4);
        $jp = $jm;
    }

    $n = $jy - $jp;
    $leapJ = $leapJ + intdiv($n, 33) * 8 + intdiv(_jalaliMod($n, 33) + 3, 4);
    if (_jalaliMod($jump, 33) === 4 && $jump - $n === 4) {
        $leapJ += 1;
    }

    $leapG = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
    $march = 20 + $leapJ - $leapG;

    if ($jump - $n < 6) {
        $n = $n - $jump + intdiv($jump, 33) * 33;
    }
    $leap = _jalaliMod(_jalaliMod($n + 1, 33) - 1, 4);
    if ($leap === -1) {
        $leap = 4;
    }

    return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
}

function _gregorianToJdn(int $gy, int $gm, int $gd): int
{
    $d = intdiv(($gy + intdiv($gm - 8, 6) + 100100) * 1461, 4)
        + intdiv(153 * _jalaliMod($gm + 9, 12) + 2, 5)
        + $gd - 34840408;
    $d = $d - intdiv(intdiv($gy + 100100 + intdiv($gm - 8, 6), 100) * 3, 4) + 752;
    return $d;
}

function _jdnToGregorian(int $jdn): array
{
    $j = 4 * $jdn + 139361631;
    $j = $j + intdiv(intdiv(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    $i = intdiv(_jalaliMod($j, 1461), 4) * 5 + 308;
    $gd = intdiv(_jalaliMod($i, 153), 5) + 1;
    $gm = _jalaliMod(intdiv($i, 153), 12) + 1;
    $gy = intdiv($j, 1461) - 100100 + intdiv(8 - $gm, 6);
    return [$gy, $gm, $gd];
}

function _jalaliToJdn(int $jy, int $jm, int $jd): int
{
    $r = _jalCal($jy);
    return _gregorianToJdn($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - intdiv($jm, 7) * ($jm - 7) + $jd - 1;
}

function _jdnToJalali(int $jdn): array
{
    [$gy] = _jdnToGregorian($jdn);
    $jy = $gy - 621;
    $r = _jalCal($jy);
    $jdn1f = _gregorianToJdn($r['gy'], 3, $r['march']);
    $k = $jdn - $jdn1f;

    if ($k >= 0) {
        if ($k <= 185) {
            $jm = 1 + intdiv($k, 31);
            $jd = _jalaliMod($k, 31) + 1;
            return [$jy, $jm, $jd];
        }
        $k -= 186;
    } else {
        $jy -= 1;
        $k += 179;
        if ($r['leap'] === 1) {
            $k += 1;
        }
    }
    $jm = 7 + intdiv($k, 30);
    $jd = _jalaliMod($k, 30) + 1;
    return [$jy, $jm, $jd];
}

// ---------------------------------------------------------------------------
// Public conversion API
// ---------------------------------------------------------------------------

/** @return array{0:int,1:int,2:int} [gregorianYear, gregorianMonth, gregorianDay] */
function jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    return _jdnToGregorian(_jalaliToJdn($jy, $jm, $jd));
}

/** @return array{0:int,1:int,2:int} [jalaliYear, jalaliMonth, jalaliDay] */
function gregorianToJalali(int $gy, int $gm, int $gd): array
{
    return _jdnToJalali(_gregorianToJdn($gy, $gm, $gd));
}

function isLeapJalaliYear(int $jy): bool
{
    return _jalCal($jy)['leap'] === 0;
}

function jalaliMonthMaxDay(int $jy, int $jm): int
{
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    return isLeapJalaliYear($jy) ? 30 : 29;
}

// ---------------------------------------------------------------------------
// Digit handling (PLAN.md section 3.2)
// ---------------------------------------------------------------------------

const _PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const _ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
const _LATIN_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

/**
 * Normalize user-typed input before parsing: Persian and Arabic-Indic
 * digits to ASCII, Arabic ي/ك to Persian ی/ک, Arabic comma to a plain
 * comma, tatweel and zero-width/direction marks stripped. Iranian phone
 * keyboards emit Persian digits by default, so this must run before any
 * date/time parsing or nearly every real user's input gets rejected.
 */
function normalizeDigits(string $s): string
{
    $s = str_replace(_PERSIAN_DIGITS, _LATIN_DIGITS, $s);
    $s = str_replace(_ARABIC_INDIC_DIGITS, _LATIN_DIGITS, $s);
    $s = str_replace(['ي', 'ك', '،', 'ـ'], ['ی', 'ک', ',', ''], $s);
    // ZWNJ, LRM, RLM
    $s = str_replace(["\u{200C}", "\u{200E}", "\u{200F}"], '', $s);
    return trim($s);
}

/** Convert ASCII digits in a string to Persian digits, for display. */
function toPersianDigits(string $s): string
{
    return str_replace(_LATIN_DIGITS, _PERSIAN_DIGITS, $s);
}

// ---------------------------------------------------------------------------
// Parsing user input
// ---------------------------------------------------------------------------

/**
 * Parse a Jalali date typed by the user (e.g. "۱۴۰۵/۰۶/۲۵", "1405-6-5").
 * Returns a Gregorian "Y-m-d" string, or null if the input isn't a
 * well-formed, valid Jalali date (bad month, day out of range for that
 * month/year -- including Esfand's 29-vs-30-day leap rule -- or a year
 * outside the algorithm's supported range).
 */
function parseJalaliDate(string $input): ?string
{
    $s = normalizeDigits($input);
    if (!preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $s, $m)) {
        return null;
    }
    $jy = (int) $m[1];
    $jm = (int) $m[2];
    $jd = (int) $m[3];

    if ($jm < 1 || $jm > 12) {
        return null;
    }

    try {
        if ($jd < 1 || $jd > jalaliMonthMaxDay($jy, $jm)) {
            return null;
        }
        [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, $jd);
    } catch (InvalidArgumentException $e) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Parse a 24h time typed by the user (e.g. "۱۴:۳۰", "9:5").
 * Returns "H:i:s", or null if malformed or out of range.
 */
function parsePersianTime(string $input): ?string
{
    $s = normalizeDigits($input);
    if (!preg_match('#^(\d{1,2})[:.](\d{1,2})$#', $s, $m)) {
        return null;
    }
    $h = (int) $m[1];
    $mi = (int) $m[2];

    if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
        return null;
    }

    return sprintf('%02d:%02d:00', $h, $mi);
}

// ---------------------------------------------------------------------------
// Formatting for display
// ---------------------------------------------------------------------------

/**
 * Format a Gregorian "Y-m-d" DB value as a Persian date string.
 * Styles:
 *   'long'      -> "چهارشنبه ۲۵ شهریور ۱۴۰۵"   (weekday + day + month + year)
 *   'day_month' -> "چهارشنبه ۲۵ شهریور"          (no year -- e.g. day pickers)
 *   'short'     -> "۱۴۰۵/۰۶/۲۵"
 */
function formatJalali(string $gregorianYmd, string $style = 'long'): string
{
    [$gy, $gm, $gd] = array_map('intval', explode('-', $gregorianYmd));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);

    if ($style === 'short') {
        return toPersianDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    $weekday = JALALI_WEEKDAY_NAMES[(int) date('N', strtotime($gregorianYmd))];
    $monthName = JALALI_MONTH_NAMES[$jm - 1];
    $dayDigits = toPersianDigits((string) $jd);

    if ($style === 'day_month') {
        return "{$weekday} {$dayDigits} {$monthName}";
    }

    // 'long' (default)
    $yearDigits = toPersianDigits((string) $jy);
    return "{$weekday} {$dayDigits} {$monthName} {$yearDigits}";
}

// ---------------------------------------------------------------------------
// RTL line safety (PLAN.md section 3.3)
// ---------------------------------------------------------------------------

/**
 * Classify a Unicode code point as strong-RTL ('R'), strong-LTR ('L'), or
 * neutral/weak ('O' -- digits, punctuation, emoji, whitespace, etc).
 * Only covers the scripts this bot can actually emit or receive (Persian/
 * Arabic and Latin); anything else falls through as neutral, which is a
 * safe default since the loop in ensureRtl() keeps scanning past it.
 */
function _bidiCharClass(int $cp): string
{
    static $rtlRanges = [
        [0x0590, 0x05FF], // Hebrew
        [0x0600, 0x0605], // Arabic (before Arabic-Indic digits)
        [0x060C, 0x061A],
        [0x061E, 0x065F],
        [0x0670, 0x06DC],
        [0x06DE, 0x06EF], // stops before 06F0-06F9 Persian digits (weak, not strong)
        [0x06FA, 0x074F],
        [0x0750, 0x077F], // Arabic Supplement
        [0x0780, 0x07BF], // Thaana
        [0x07C0, 0x085F], // NKo, Samaritan, Mandaic
        [0x08A0, 0x08FF], // Arabic Extended-A
        [0xFB1D, 0xFDFF], // Hebrew/Arabic presentation forms A
        [0xFE70, 0xFEFF], // Arabic presentation forms B
    ];
    static $ltrRanges = [
        [0x0041, 0x005A], // A-Z
        [0x0061, 0x007A], // a-z
        [0x00C0, 0x02AF], // Latin-1 Supplement, Latin Extended A/B
    ];

    foreach ($rtlRanges as [$lo, $hi]) {
        if ($cp >= $lo && $cp <= $hi) {
            return 'R';
        }
    }
    foreach ($ltrRanges as [$lo, $hi]) {
        if ($cp >= $lo && $cp <= $hi) {
            return 'L';
        }
    }
    return 'O';
}

/**
 * Guarantee a line of text renders right-to-left in Telegram. Telegram
 * (like any bidi-aware renderer) picks a line's direction from its first
 * *strong* character, skipping over neutrals and weak characters (digits,
 * emoji, punctuation) per the Unicode bidi algorithm's P2 rule -- so
 * "📅 ۱۴۰۵/۰۶/۲۵ — چهارشنبه" is already fine as-is (the digits are weak,
 * "چهارشنبه" is the first strong character). This only needs to act when
 * a line's first strong character is Latin (e.g. a bare @username or an
 * English description with no Persian word before it on that line), in
 * which case it prepends an RLM (U+200F) to force RTL layout.
 */
function ensureRtl(string $s): string
{
    if ($s === '' || !preg_match_all('/./us', $s, $mm)) {
        return $s;
    }
    foreach ($mm[0] as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            continue;
        }
        $class = _bidiCharClass($cp);
        if ($class === 'R') {
            return $s; // already starts with a strong RTL character
        }
        if ($class === 'L') {
            return "\u{200F}" . $s; // starts with strong LTR -- force RTL
        }
        // 'O': neutral/weak, keep scanning for the first strong character
    }
    return $s; // no strong character on the line at all -- leave as-is
}
