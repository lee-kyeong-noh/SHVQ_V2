<?php
declare(strict_types=1);

final class DbConnection
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/../../../config/database.php';

        $dsn = sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['encrypt'] ? 'yes' : 'no',
            $config['trust_server_certificate'] ? 'yes' : 'no'
        );

        self::$pdo = new PDO(
            $dsn,
            (string)$config['username'],
            (string)$config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return self::$pdo;
    }
}
