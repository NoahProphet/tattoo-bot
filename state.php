<?php

/**
 * Conversation steps:
 *   idle              -> not in the middle of anything
 *   awaiting_date     -> waiting for the user to type a date
 *   awaiting_time     -> waiting for the user to type a time
 *   awaiting_desc     -> waiting for a tattoo description (or "skip")
 *   awaiting_confirm  -> waiting for the user to tap Confirm/Cancel
 */

function getState(int $telegramId): array
{
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM bot_states WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    return [
        'telegram_id' => $telegramId,
        'username'    => null,
        'step'        => 'idle',
        'temp_date'   => null,
        'temp_time'   => null,
        'temp_desc'   => null,
    ];
}

function saveState(int $telegramId, array $data): void
{
    $pdo = getDb();
    $stmt = $pdo->prepare(
        'INSERT INTO bot_states (telegram_id, username, step, temp_date, temp_time, temp_desc)
         VALUES (:telegram_id, :username, :step, :temp_date, :temp_time, :temp_desc)
         ON DUPLICATE KEY UPDATE
            username  = VALUES(username),
            step      = VALUES(step),
            temp_date = VALUES(temp_date),
            temp_time = VALUES(temp_time),
            temp_desc = VALUES(temp_desc)'
    );
    $stmt->execute([
        'telegram_id' => $telegramId,
        'username'    => $data['username'] ?? null,
        'step'        => $data['step'] ?? 'idle',
        'temp_date'   => $data['temp_date'] ?? null,
        'temp_time'   => $data['temp_time'] ?? null,
        'temp_desc'   => $data['temp_desc'] ?? null,
    ]);
}

function resetState(int $telegramId): void
{
    saveState($telegramId, ['step' => 'idle', 'temp_date' => null, 'temp_time' => null, 'temp_desc' => null]);
}
