<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function getDB(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    loadEnv(__DIR__ . '/../.env');

    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'ContactsAppDB';
    $user = getenv('DB_USER') ?: 'ContactsAppUser';
    $password = getenv('DB_PASSWORD');
    $port = getenv('DB_PORT') ?: '3306';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    if ($password === false) {
        respond(500, ['error' => 'Database configuration is incomplete']);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $port,
        $name,
        $charset
    );

    try {
        $db = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('Contacts API database connection failed: ' . $exception->getMessage());
        respond(500, ['error' => 'Database connection error']);
    }

    return $db;
}
