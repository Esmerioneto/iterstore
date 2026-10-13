<?php

declare(strict_types=1);

namespace Iter\Core\Db;

class Connection
{
    private static ?Connection $conn = null;

    // one PDO per request, so every caller shares the same session and transaction
    private ?\PDO $pdo = null;

    private ?Adapter $adapter = null;

    protected function __construct()
    {
    }

    public function connect(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $params = parse_ini_file(ETC . 'database.ini');
        $params['database'] = getenv('ITER_DB_NAME') ?: $params['database']; // a throwaway copy (bin/db-bench.php)

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
            throw new ConnectionException(
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

        return $this->pdo = $pdo;
    }

    public function adapter(): Adapter
    {
        return $this->adapter ??= new Adapter($this->connect());
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
