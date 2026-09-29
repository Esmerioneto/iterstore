<?php

declare(strict_types=1);

/**
 * Writes schema.graphql, the API contract for the external frontends (React, Go,
 * PWA, mobile), from the modules enabled now. Run after any API change:
 *
 *     php bin/schema-dump.php
 *
 * The admin selftest fails while the committed file is out of date.
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

file_put_contents(__DIR__ . '/../schema.graphql', GraphQL\Utils\SchemaPrinter::doPrint(Iter\Core\GraphQl\Schema::build()));
echo "schema.graphql atualizado.\n";
