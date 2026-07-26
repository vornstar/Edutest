<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Thin PDO singleton. All queries elsewhere use prepared statements.
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = config('db.host');
        $port = config('db.port');
        $name = config('db.name');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        self::$instance = new PDO($dsn, config('db.user'), config('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$instance;
    }
}
