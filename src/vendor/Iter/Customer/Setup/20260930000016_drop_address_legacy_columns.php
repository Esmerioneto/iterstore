<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

/**
 * CUSTOMER-EAV-PLAN delivery 6: the legacy_* address columns kept by 20260930000015 go away.
 * Stops if an address lacks a required field in the EAV (the copy would be incomplete).
 */
return new class implements MigrationInterface {
    /** column => type, as they were in customer_address */
    private const COLUMNS = [
        'postcode' => 'CHAR(8)', 'street' => 'VARCHAR(255)', 'number' => 'VARCHAR(20)', 'complement' => 'VARCHAR(255)',
        'district' => 'VARCHAR(255)', 'city' => 'VARCHAR(255)', 'region' => 'CHAR(2)', 'recipient' => 'VARCHAR(255)', 'telephone' => 'VARCHAR(20)',
    ];

    public function up(PDO $pdo): void
    {
        $missing = $pdo->query('SELECT string_agg(address_id::text, \', \') FROM customer_address_flat
            WHERE postcode IS NULL OR street IS NULL OR number IS NULL OR district IS NULL OR city IS NULL OR region IS NULL')->fetchColumn();
        if ($missing) {
            throw new RuntimeException("Endereços sem campo obrigatório no EAV; confira antes de apagar as colunas legacy_*: {$missing}");
        }
        $pdo->exec('ALTER TABLE customer_address ' . implode(', ', array_map(
            static fn ($column) => "DROP COLUMN legacy_{$column}",
            array_keys(self::COLUMNS)
        )));
    }

    /** the columns come back empty; 20260930000015's down() fills them from customer_address_flat */
    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE customer_address ' . implode(', ', array_map(
            static fn ($column, $type) => "ADD COLUMN legacy_{$column} {$type}",
            array_keys(self::COLUMNS),
            self::COLUMNS
        )));
    }
};
