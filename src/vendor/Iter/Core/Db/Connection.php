<?php

declare(strict_types=1);

namespace Iter\Core\Db;

class Connection
{
    private static ?Connection $conn = null;

    protected function __construct()
    {
    }

    public function connect(): \PDO
    {
        $params = parse_ini_file(ETC . 'database.ini');

        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['password']
        );

        try {
            $pdo = new \PDO($conStr);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Não foi possível conectar ao banco de dados PostgreSQL. '
                . 'Verifique se o container Docker do banco está em execução ("docker compose up -d"). '
                . 'Erro original: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // NOW()/CURRENT_TIMESTAMP must match PHP's date(), both land in the same columns
        $pdo->exec("SET TIME ZONE '" . date_default_timezone_get() . "'");

        return $pdo;
    }

    public static function get(): Connection
    {
        if (null === static::$conn) {
            static::$conn = new static();
        }

        return static::$conn;
    }

    public function __clone()
    {
        throw new \Exception("Cannot clone a singleton.");
    }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }
}
