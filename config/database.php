<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Database (PDO singleton)
   ========================================================= */

/**
 * Get a shared PDO connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        if (APP_ENV === 'local') {
            die('Database connection failed: ' . $e->getMessage());
        }
        http_response_code(500);
        die('Service temporarily unavailable.');
    }

    return $pdo;
}