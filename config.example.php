<?php
/**
 * Copy this file to config.php and fill in your real values.
 * config.php should NEVER be committed to version control.
 */
return [
    // Get this from @BotFather on Telegram
    'bot_token' => 'YOUR_BOT_TOKEN_HERE',

    // Telegram numeric chat ID of the tattoo artist (the account that approves/rejects).
    // To find it: message @userinfobot on Telegram, or temporarily log
    // $update['message']['from']['id'] in webhook.php when the artist sends /start.
    'artist_chat_id' => 123456789,

    // Optional: secret token used to verify that requests really come from Telegram.
    // Set the same value when calling setWebhook (see README) and leave blank to disable.
    'webhook_secret' => '',

    'db' => [
        'host' => '127.0.0.1',
        'name' => 'tattoo_bot',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    // Used only for displaying dates nicely; doesn't affect what's stored.
    'timezone' => 'Europe/Berlin',
];
