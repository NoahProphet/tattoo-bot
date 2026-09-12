<?php

require_once __DIR__ . '/jalali.php';

/**
 * Apply ensureRtl() (see jalali.php) to every line of a message before it
 * goes to Telegram. This is the enforced backstop for RTL-safe output --
 * templates in lang/fa.php should still lead with a Persian label where
 * possible, but this guarantees a line never renders backwards even if a
 * value (a bare @username, an English description) ends up first.
 */
function tgRtlSafe(string $text): string
{
    $lines = explode("\n", $text);
    foreach ($lines as &$line) {
        $line = ensureRtl($line);
    }
    return implode("\n", $lines);
}

function tgApiRequest(string $method, array $params = []): array
{
    $config = require __DIR__ . '/config.php';
    $url = "https://api.telegram.org/bot{$config['bot_token']}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        error_log('Telegram API cURL error: ' . curl_error($ch));
        curl_close($ch);
        return ['ok' => false];
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false];
    }
    if (empty($decoded['ok'])) {
        error_log('Telegram API error on ' . $method . ': ' . $response);
    }
    return $decoded;
}

function tgSendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
{
    $params = [
        'chat_id'    => $chatId,
        'text'       => tgRtlSafe($text),
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    return tgApiRequest('sendMessage', $params);
}

function tgEditMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): array
{
    $params = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => tgRtlSafe($text),
        'parse_mode' => 'HTML',
    ];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    return tgApiRequest('editMessageText', $params);
}

function tgAnswerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
{
    return tgApiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text'              => tgRtlSafe($text),
        'show_alert'        => $showAlert,
    ]);
}

/**
 * Build an inline keyboard.
 * $rows = [ [ ['text'=>'Yes','callback_data'=>'yes'], ['text'=>'No','callback_data'=>'no'] ], [...] ]
 */
function tgInlineKeyboard(array $rows): array
{
    return ['inline_keyboard' => $rows];
}
