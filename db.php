<?php

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';
        $db = $config['db'];

        if (!empty($db['unix_socket'])) {
            $dsn = "mysql:unix_socket={$db['unix_socket']};dbname={$db['name']};charset={$db['charset']}";
        } else {
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
        }

        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
