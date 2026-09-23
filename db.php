<?php
// ============================================================
//  DB.PHP – connects to the MySQL database using PDO
// ============================================================

require_once __DIR__ . '/config.php';

/**
 * Returns a PDO connection to the 'ai_chat' database.
 *
 * PDO is PHP's recommended way to talk to MySQL.
 * It is safe from SQL injection because we always use
 * prepared statements (see login.php and api/chat.php).
 */
function getPDO() {
    // The DSN (data source name) tells PDO where the database is.
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS);

    // Make errors throw exceptions so the pages can show a clear message.
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}