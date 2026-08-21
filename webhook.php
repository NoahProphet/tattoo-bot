<?php
/**
 * Telegram webhook entry point.
 * Set this file's public URL as your bot's webhook (see README.md).
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/telegram.php';
require __DIR__ . '/state.php';

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
        tgSendMessage(
            $chatId,
            "👋 Welcome to the studio's booking assistant!\n\n" .
            "• /book — request a tattoo appointment\n" .
            "• /myappointments — check the status of your requests\n" .
            "• /cancel — cancel whatever you're doing"
        );
        return;
    }

    if ($text === '/cancel') {
        resetState($telegramId);
        tgSendMessage($chatId, 'Okay, cancelled. Send /book whenever you want to start again.');
        return;
    }

    if ($text === '/book') {
        saveState($telegramId, ['username' => $username, 'step' => 'awaiting_date']);
        tgSendMessage($chatId, "Let's book your tattoo session! 🖋️\n\nWhat date would you like? (format: YYYY-MM-DD)");
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
            tgSendMessage($chatId, "I didn't quite get that. Send /book to request an appointment.");
    }
}

function handleDateInput(int $chatId, int $telegramId, ?string $username, string $text): void
{
    $date = DateTime::createFromFormat('Y-m-d', $text);
    $errors = DateTime::getLastErrors();

    if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        tgSendMessage($chatId, "That doesn't look like a valid date. Please use the format YYYY-MM-DD (e.g. 2026-09-15).");
        return;
    }

    $today = new DateTime('today');
    if ($date < $today) {
        tgSendMessage($chatId, 'That date is in the past 🙂 Please choose a future date (YYYY-MM-DD).');
        return;
    }

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_time',
        'temp_date' => $date->format('Y-m-d'),
    ]);
    tgSendMessage($chatId, 'Great. What time works for you? (format: HH:MM, 24h — e.g. 14:30)');
}

function handleTimeInput(int $chatId, int $telegramId, ?string $username, string $text, array $state): void
{
    $time = DateTime::createFromFormat('H:i', $text);
    $errors = DateTime::getLastErrors();

    if (!$time || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        tgSendMessage($chatId, "That doesn't look like a valid time. Please use the format HH:MM (e.g. 14:30).");
        return;
    }

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_desc',
        'temp_date' => $state['temp_date'],
        'temp_time' => $time->format('H:i:s'),
    ]);
    tgSendMessage(
        $chatId,
        "Got it. Briefly describe the tattoo you'd like (style, size, placement).\n" .
        "Send \"skip\" if you'd rather discuss it in person."
    );
}

function handleDescInput(int $chatId, int $telegramId, ?string $username, string $text, array $state): void
{
    $desc = (strtolower($text) === 'skip') ? null : $text;

    saveState($telegramId, [
        'username'  => $username,
        'step'      => 'awaiting_confirm',
        'temp_date' => $state['temp_date'],
        'temp_time' => $state['temp_time'],
        'temp_desc' => $desc,
    ]);

    $dateFmt = (new DateTime($state['temp_date']))->format('D, d M Y');
    $timeFmt = substr($state['temp_time'], 0, 5);
    $descText = $desc ? htmlspecialchars($desc) : '<i>Not specified</i>';

    $summary = "Please confirm your request:\n\n" .
        "📅 <b>Date:</b> {$dateFmt}\n" .
        "⏰ <b>Time:</b> {$timeFmt}\n" .
        "📝 <b>Description:</b> {$descText}";

    $keyboard = tgInlineKeyboard([[
        ['text' => '✅ Confirm', 'callback_data' => 'confirm_booking'],
        ['text' => '❌ Cancel', 'callback_data' => 'cancel_booking'],
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
        tgSendMessage($chatId, "You don't have any appointment requests yet. Send /book to create one.");
        return;
    }

    $statusEmoji = ['pending' => '⏳', 'approved' => '✅', 'rejected' => '❌', 'cancelled' => '🚫'];
    $lines = [];
    foreach ($rows as $row) {
        $emoji = $statusEmoji[$row['status']] ?? '';
        $lines[] = "{$emoji} {$row['appointment_date']} at " . substr($row['appointment_time'], 0, 5) .
            ' — ' . ucfirst($row['status']);
    }

    tgSendMessage($chatId, "Your recent requests:\n\n" . implode("\n", $lines));
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
        tgAnswerCallbackQuery($callbackId, 'Cancelled');
        tgEditMessageText($chatId, $messageId, 'Booking request cancelled. Send /book to start a new one.');
        return;
    }

    if (str_starts_with($data, 'approve_') || str_starts_with($data, 'reject_')) {
        // Only the configured artist account may approve/reject.
        if ($fromId !== (int) $config['artist_chat_id']) {
            tgAnswerCallbackQuery($callbackId, 'Only the artist can do that.', true);
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
        tgAnswerCallbackQuery($callbackId, 'Nothing to confirm — send /book to start.', true);
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

    tgAnswerCallbackQuery($callbackId, 'Sent to the artist!');
    tgEditMessageText($chatId, $messageId, "✅ Your request has been sent to the artist. You'll be notified as soon as it's reviewed.");

    // Notify the artist with an Approve/Reject keyboard.
    $dateFmt = (new DateTime($state['temp_date']))->format('D, d M Y');
    $timeFmt = substr($state['temp_time'], 0, 5);
    $descText = $state['temp_desc'] ? htmlspecialchars($state['temp_desc']) : '<i>Not specified</i>';
    $userLabel = $state['username'] ? '@' . htmlspecialchars($state['username']) : "user #{$telegramId}";

    $artistText = "🆕 <b>New appointment request</b> (#{$appointmentId})\n\n" .
        "👤 {$userLabel}\n📅 {$dateFmt}\n⏰ {$timeFmt}\n📝 {$descText}";

    $keyboard = tgInlineKeyboard([[
        ['text' => '✅ Approve', 'callback_data' => "approve_{$appointmentId}"],
        ['text' => '❌ Reject', 'callback_data' => "reject_{$appointmentId}"],
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
        tgAnswerCallbackQuery($callbackId, 'Appointment not found.', true);
        return;
    }

    if ($appointment['status'] !== 'pending') {
        tgAnswerCallbackQuery($callbackId, 'This request was already ' . $appointment['status'] . '.', true);
        return;
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';

    $update = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $update->execute([$newStatus, $appointmentId]);

    $dateFmt = (new DateTime($appointment['appointment_date']))->format('D, d M Y');
    $timeFmt = substr($appointment['appointment_time'], 0, 5);
    $decisionLabel = $newStatus === 'approved' ? '✅ Approved' : '❌ Rejected';

    tgAnswerCallbackQuery($callbackId, $decisionLabel);
    tgEditMessageText(
        $artistChatId,
        $messageId,
        "Request #{$appointmentId} — {$dateFmt} at {$timeFmt}\nStatus: {$decisionLabel}"
    );

    // Notify the client of the decision.
    if ($newStatus === 'approved') {
        $clientText = "🎉 Great news! Your tattoo appointment has been <b>approved</b>:\n\n" .
            "📅 {$dateFmt}\n⏰ {$timeFmt}\n\nSee you then!";
    } else {
        $clientText = "😔 Unfortunately your requested slot ({$dateFmt} at {$timeFmt}) was <b>declined</b>. " .
            'Send /book to try another date/time.';
    }

    tgSendMessage((int) $appointment['telegram_id'], $clientText);
}
