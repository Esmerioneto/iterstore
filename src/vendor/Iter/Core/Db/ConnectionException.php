<?php

declare(strict_types=1);

namespace Iter\Core\Db;

// the database can't be reached; public/index.php shows a friendly page instead of the trace
class ConnectionException extends \RuntimeException
{
}
