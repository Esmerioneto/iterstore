<?php

declare(strict_types=1);

namespace Iter\Core\Db;

/**
 * Raw SQL that Select/Adapter write as is: NOW(), COUNT(*), EXCLUDED.quantity.
 * Only for code-written SQL, never for user input (that goes as a bound value).
 * Mirrors Zend_Db_Expr.
 */
final class Expr
{
    public function __construct(private readonly string $sql)
    {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
