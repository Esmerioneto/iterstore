<?php

declare(strict_types=1);

use Iter\Core\Setup\MigrationInterface;

return new class implements MigrationInterface {
    /** storage_type => column type; multiselect stores a JSON array in "text" */
    private const TABLES = [
        'varchar' => 'VARCHAR(255)',
        'text' => 'TEXT',
        'int' => 'BIGINT',
        'decimal' => 'NUMERIC(20,6)',
        'datetime' => 'TIMESTAMP',
        'boolean' => 'SMALLINT',
    ];

    public function up(PDO $pdo): void
    {
        foreach (self::TABLES as $storage => $column) {
            // attribute_id already belongs to one entity type, so (attribute_id, entity_id) can't collide
            // across entities. ponytail: no FK on entity_id, since entity tables don't exist yet; whoever
            // deletes an entity calls ValueService::deleteEntity()
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS eav_value_{$storage} (
                    attribute_id INT NOT NULL REFERENCES eav_attribute (attribute_id) ON DELETE CASCADE,
                    entity_id INT NOT NULL,
                    value {$column} NOT NULL,
                    PRIMARY KEY (attribute_id, entity_id)
                )"
            );
            $pdo->exec("CREATE INDEX IF NOT EXISTS eav_value_{$storage}_entity ON eav_value_{$storage} (entity_id)");
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (array_keys(self::TABLES) as $storage) {
            $pdo->exec("DROP TABLE IF EXISTS eav_value_{$storage}");
        }
    }
};
