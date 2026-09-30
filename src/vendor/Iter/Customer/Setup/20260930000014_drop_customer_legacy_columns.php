<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * CUSTOMER-EAV-PLAN delivery 4: the legacy_* columns kept by 20260930000012 as a safety net go away.
 * Stops if a customer lacks a required registration value in the EAV (the copy would be incomplete).
 */
return new class implements MigrationInterface {
    /** column => type, as they were in customer_entity */
    private const COLUMNS = [
        'email' => 'VARCHAR(255)', 'firstname' => 'VARCHAR(255)', 'lastname' => 'VARCHAR(255)', 'customer_type' => 'CHAR(2)',
        'taxvat' => 'VARCHAR(14)', 'company_name' => 'VARCHAR(255)', 'trade_name' => 'VARCHAR(255)',
        'state_registration' => 'VARCHAR(20)', 'is_ie_exempt' => 'SMALLINT', 'dob' => 'DATE',
    ];

    public function up(PDO $pdo): void
    {
        $missing = $pdo->query('SELECT string_agg(customer_id::text, \', \') FROM customer_flat
            WHERE email IS NULL OR firstname IS NULL OR lastname IS NULL OR customer_type IS NULL')->fetchColumn();
        if ($missing) {
            throw new RuntimeException("Clientes sem e-mail, nome ou tipo no EAV; confira antes de apagar as colunas legacy_*: {$missing}");
        }
        $pdo->exec('ALTER TABLE customer_entity ' . implode(', ', array_map(
            static fn ($column) => "DROP COLUMN legacy_{$column}",
            array_keys(self::COLUMNS)
        )));
    }

    /** the columns come back empty; 20260930000012's down() fills them from customer_flat */
    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE customer_entity ' . implode(', ', array_map(
            static fn ($column, $type) => "ADD COLUMN legacy_{$column} {$type}",
            array_keys(self::COLUMNS),
            self::COLUMNS
        )));
        $pdo->exec("ALTER TABLE customer_entity ADD CONSTRAINT customer_entity_customer_type_check CHECK (legacy_customer_type IN ('pf', 'pj'))");
    }
};
