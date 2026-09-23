<?php

declare(strict_types=1);

namespace Iter\Core\Controllers;

use Iter\Core\GraphQl\Schema;

class Graphql
{
    public function Index(): void
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $query = $input['query'] ?? '';
        $variables = $input['variables'] ?? null;

        $result = \GraphQL\GraphQL::executeQuery(Schema::build(), $query, null, null, $variables);

        echo json_encode($result->toArray());
    }
}
