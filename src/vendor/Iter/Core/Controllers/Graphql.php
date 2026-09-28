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

        $schema = Schema::build();
        if ($schema === null) {
            echo json_encode(['errors' => [['message' => 'Nenhum módulo com API está ativo.']]]);
            return;
        }
        $result = \GraphQL\GraphQL::executeQuery($schema, $query, null, null, $variables);

        echo json_encode($result->toArray());
    }
}
