<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

// the customer's own "Telefone" (Dados pessoais) and the address one had the same label on the same form
return new class implements MigrationInterface {
    public function up(PDO $pdo): void
    {
        $pdo->exec("UPDATE eav_attribute SET label = 'Telefone do destinatário' WHERE code = 'telephone' AND label = 'Telefone'
            AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer_address')");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec("UPDATE eav_attribute SET label = 'Telefone' WHERE code = 'telephone' AND label = 'Telefone do destinatário'
            AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE code = 'customer_address')");
    }
};
