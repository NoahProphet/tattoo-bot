<?php

/**
 * Loads the Persian string catalog (lang/fa.php) and exposes t() to look
 * up and format a string by key. See PLAN.md section 3.4.
 */

function faDict(): array
{
    static $dict = null;
    if ($dict === null) {
        $dict = require __DIR__ . '/lang/fa.php';
    }
    return $dict;
}

/**
 * Look up a string by key and substitute {placeholders}. A missing key
 * returns a visibly-wrong placeholder instead of fataling -- a typo in a
 * translation key shouldn't take the whole webhook down.
 */
function t(string $key, array $params = []): string
{
    $text = faDict()[$key] ?? "?{$key}?";

    if ($params) {
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        $text = strtr($text, $replace);
    }

    return $text;
}
