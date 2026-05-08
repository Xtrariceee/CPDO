<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        // Ping the connection — reconnect if it dropped (e.g. MySQL gone-away)
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            $pdo = null; // force reconnect below
        }
    }

    $db  = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['database'],
        $db['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Note: persistent connections are intentionally NOT used here.
        // PDO::ATTR_PERSISTENT causes lastInsertId() to return 0 on reused
        // pool connections, which breaks any code that inserts a parent row
        // then immediately inserts child rows using the returned ID.
    ];

    // Retry up to 3 times with a short back-off (handles transient
    // "connection refused" spikes when XAMPP is under load).
    $attempts = 0;
    $lastError = null;
    while ($attempts < 3) {
        try {
            $pdo = new PDO($dsn, $db['username'], $db['password'], $options);
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e;
            $attempts++;
            if ($attempts < 3) {
                usleep(150_000); // wait 150 ms before retrying
            }
        }
    }

    // All retries exhausted — log and show a friendly error page
    error_log('[CPDO DB] Connection failed after 3 attempts: ' . $lastError->getMessage());
    http_response_code(503);
    // Avoid leaking DSN details to the browser
    exit(
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Service Unavailable</title></head><body style="font-family:sans-serif;padding:40px;">'
        . '<h2>Database temporarily unavailable</h2>'
        . '<p>The server could not connect to the database. '
        . 'Please try again in a moment or contact the administrator.</p>'
        . '</body></html>'
    );
}

function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;

    return $cache[$key];
}
