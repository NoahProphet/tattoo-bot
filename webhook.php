<?php
/**
 * Telegram webhook entry point.
 * Set this file's public URL as your bot's webhook (see README.md).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/jalali.php';
require_once __DIR__ . '/lang.php';

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

// --- Verify the request actually came from Telegram (optional but recommended) ---
if (!empty($config['webhook_secret'])) {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($config['webhook_secret'], $header)) {
        http_response_code(403);
        exit;
    }
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!is_array($update)) {
    http_response_code(200); // ack anyway so Telegram doesn't retry
    exit;
}

try {
    if (isset($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
    }
} catch (Throwable $e) {
    error_log('Webhook error: ' . $e->getMessage());
}

http_response_code(200);
exit;

// ==========================================================================
// Small helpers
// ==========================================================================

/** A near-future example date shown in prompts/errors, so it's always valid. */
function jalaliDateExample(): string
{
    return formatJalali((new DateTime('+7 days'))->format('Y-m-d'), 'short');
}

/** A fixed example time shown in prompts/errors, in Persian digits. */
function jalaliTimeExample(): string
{
    return toPersianDigits('14:30');
}

/** Accept a handful of ways a client might say "no description, thanks". */
function isSkipWord(string $text): bool
{
    $normalized = mb_strtolower(normalizeDigits(trim($text)), 'UTF-8');
    return in_array($normalized, ['skip', 'رد شدن', 'رد کردن', 'بدون توضیح', 'ندارد', '-'], true);
}

// ==========================================================================
// Message handling (text commands + step-by-step booking flow)
// ==========================================================================

function handleMessage(array $message): void
{
    if (!isset($message['text'])) {
        return; // ignore photos, stickers, etc.
    }

    $chatId = (int) $message['chat']['id'];
    $telegramId = (int) $message['from']['id'];
    $username = $message['from']['username'] ?? ($message['from']['first_name'] ?? null);
    $text = trim($message['text']);

    if ($text === '/start') {
        resetState($telegramId);
        tgSendMessage($chatId, t('start_welcome'));
        return;
    }

    if ($text === '/cancel') {
        resetState($telegramId);
        tgSendMessage($chatId, t('cancel_done'));
        return;
    }

    if ($text === '/book') {
        saveState($telegramId, ['username' => $username, 'step' => 'awaiting_date']);
        tgSendMessage($chatId, t('book_ask_date', ['example' => jalaliDateExample()]));
        return;
    }

    if ($text === '/myappointments') {
        listAppointments($chatId, $telegramId);
        return;
    }

    $state = getState($telegramId);

    switch ($state['step']) {
        case 'awaiting_date':
            handleDateInput($chatId, $telegramId, $username, $text);
            break;
        case 'awaiting_time':
            handleTimeInput($chatId, $telegramId, $username, $text, $state);
            break;
        case 'awaiting_desc':
            handleDescInput($chatId, $telegramId, $username, $text, $state);
            break;
        default:
            tgSendMessage($chatId, t('fallback_unknown'));
    }
}

function handleDateInput(int $chatId, int $telegramId, ?string $username, string $text): void
{
    $gregorianDate = parseJalaliDate($text);

    if ($gregorianDate === null) {
        tgSendMessage($chatId, t('date_invalid', ['example' => jalaliDateExample()]));
        return;
    }

    $today = new DateTime('today');
    if (new DateTime($gregorianDate) < $today) {
        tgSendMessage($chatId, t('date_past'));
        return;
    }

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_time',
        'temp_date' => $gregorianDate,
    ]);
    tgSendMessage($chatId, t('book_ask_time', ['example' => jalaliTimeExample()]));
}

function handleTimeInput(int $chatId, int $telegramId, ?string $username, string $text, array $state): void
{
    $time = parsePersianTime($text);

    if ($time === null) {
        tgSendMessage($chatId, t('time_invalid', ['example' => jalaliTimeExample()]));
        return;
    }

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_desc',
        'temp_date' => $state['temp_date'],
        'temp_time' => $time,
    ]);
    tgSendMessage($chatId, t('book_ask_desc'));
}

function handleDescInput(int $chatId, int $telegramId, ?string $username, string $text, array $state): void
{
    $desc = isSkipWord($text) ? null : $text;

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_confirm',
        'temp_date' => $state['temp_date'],
        'temp_time' => $state['temp_time'],
        'temp_desc' => $desc,
    ]);

    $dateFmt = formatJalali($state['temp_date'], 'long');
    $timeFmt = toPersianDigits(substr($state['temp_time'], 0, 5));
    $descText = $desc ? htmlspecialchars($desc) : '<i>' . t('confirm_desc_none') . '</i>';

    $summary = t('confirm_summary', ['date' => $dateFmt, 'time' => $timeFmt, 'desc' => $descText]);

    $keyboard = tgInlineKeyboard([[
        ['text' => t('btn_confirm'), 'callback_data' => 'confirm_booking'],
        ['text' => t('btn_cancel_inline'), 'callback_data' => 'cancel_booking'],
    ]]);

    tgSendMessage($chatId, $summary, $keyboard);
}

function listAppointments(int $chatId, int $telegramId): void
{
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE telegram_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$telegramId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        tgSendMessage($chatId, t('myappt_empty'));
        return;
    }

    $statusEmoji = ['pending' => '⏳', 'approved' => '✅', 'rejected' => '❌', 'cancelled' => '🚫'];
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = t('myappt_line', [
            'emoji'  => $statusEmoji[$row['status']] ?? '',
            'date'   => formatJalali($row['appointment_date'], 'short'),
            'time'   => toPersianDigits(substr($row['appointment_time'], 0, 5)),
            'status' => t('status_' . $row['status']),
        ]);
    }

    tgSendMessage($chatId, t('myappt_header') . "\n\n" . implode("\n", $lines));
}

// ==========================================================================
// Callback (inline button) handling
// ==========================================================================

function handleCallback(array $callback): void
{
    $config = require __DIR__ . '/config.php';

    $callbackId = $callback['id'];
    $data = $callback['data'] ?? '';
    $fromId = (int) $callback['from']['id'];
    $chatId = (int) $callback['message']['chat']['id'];
    $messageId = (int) $callback['message']['message_id'];

    if ($data === 'confirm_booking') {
        confirmBooking($callbackId, $chatId, $fromId, $messageId);
        return;
    }

    if ($data === 'cancel_booking') {
        resetState($fromId);
        tgAnswerCallbackQuery($callbackId, t('cancel_toast'));
        tgEditMessageText($chatId, $messageId, t('cancel_edited'));
        return;
    }

    if (str_starts_with($data, 'approve_') || str_starts_with($data, 'reject_')) {
        // Only the configured artist account may approve/reject.
        if ($fromId !== (int) $config['artist_chat_id']) {
            tgAnswerCallbackQuery($callbackId, t('artist_only'), true);
            return;
        }
        [$action, $appointmentIdRaw] = explode('_', $data, 2);
        handleArtistDecision($callbackId, $chatId, $messageId, (int) $appointmentIdRaw, $action);
        return;
    }

    tgAnswerCallbackQuery($callbackId);
}

function confirmBooking(string $callbackId, int $chatId, int $telegramId, int $messageId): void
{
    $config = require __DIR__ . '/config.php';
    $state = getState($telegramId);

    if ($state['step'] !== 'awaiting_confirm' || !$state['temp_date'] || !$state['temp_time']) {
        tgAnswerCallbackQuery($callbackId, t('confirm_nothing'), true);
        return;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare(
        'INSERT INTO appointments (telegram_id, username, appointment_date, appointment_time, description, status)
         VALUES (?, ?, ?, ?, ?, "pending")'
    );
    $stmt->execute([
        $telegramId,
        $state['username'],
        $state['temp_date'],
        $state['temp_time'],
        $state['temp_desc'],
    ]);
    $appointmentId = (int) $pdo->lastInsertId();

    resetState($telegramId);

    tgAnswerCallbackQuery($callbackId, t('confirm_toast'));
    tgEditMessageText($chatId, $messageId, t('confirm_sent_client'));

    // Notify the artist with an Approve/Reject keyboard.
    $dateFmt = formatJalali($state['temp_date'], 'long');
    $timeFmt = toPersianDigits(substr($state['temp_time'], 0, 5));
    $descText = $state['temp_desc'] ? htmlspecialchars($state['temp_desc']) : '<i>' . t('confirm_desc_none') . '</i>';
    $userLabel = $state['username']
        ? '@' . htmlspecialchars($state['username'])
        : t('artist_user_anon', ['id' => toPersianDigits((string) $telegramId)]);

    $artistText = t('artist_notify', [
        'id'   => toPersianDigits((string) $appointmentId),
        'user' => $userLabel,
        'date' => $dateFmt,
        'time' => $timeFmt,
        'desc' => $descText,
    ]);

    $keyboard = tgInlineKeyboard([[
        ['text' => t('btn_approve'), 'callback_data' => "approve_{$appointmentId}"],
        ['text' => t('btn_reject'), 'callback_data' => "reject_{$appointmentId}"],
    ]]);

    tgSendMessage((int) $config['artist_chat_id'], $artistText, $keyboard);
}

function handleArtistDecision(string $callbackId, int $artistChatId, int $messageId, int $appointmentId, string $action): void
{
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ?');
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        tgAnswerCallbackQuery($callbackId, t('decision_not_found'), true);
        return;
    }

    if ($appointment['status'] !== 'pending') {
        tgAnswerCallbackQuery($callbackId, t('decision_already', [
            'status' => t('status_' . $appointment['status']),
        ]), true);
        return;
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';

    $update = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $update->execute([$newStatus, $appointmentId]);

    $dateFmt = formatJalali($appointment['appointment_date'], 'long');
    $timeFmt = toPersianDigits(substr($appointment['appointment_time'], 0, 5));
    $decisionLabel = $newStatus === 'approved' ? t('decision_label_approved') : t('decision_label_rejected');
    $toastText = $newStatus === 'approved' ? t('decision_toast_approved') : t('decision_toast_rejected');

    tgAnswerCallbackQuery($callbackId, $toastText);
    tgEditMessageText(
        $artistChatId,
        $messageId,
        t('decision_edited', [
            'id'       => toPersianDigits((string) $appointmentId),
            'date'     => $dateFmt,
            'time'     => $timeFmt,
            'decision' => $decisionLabel,
        ])
    );

    // Notify the client of the decision.
    $clientText = $newStatus === 'approved'
        ? t('client_approved', ['date' => $dateFmt, 'time' => $timeFmt])
        : t('client_rejected', ['date' => $dateFmt, 'time' => $timeFmt]);

    tgSendMessage((int) $appointment['telegram_id'], $clientText);
}
