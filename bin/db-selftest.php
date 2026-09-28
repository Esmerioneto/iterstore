<?php

declare(strict_types=1);

/**
 * Iter\Core\Db: the SQL Select builds and the Adapter against the database.
 * Uses a TEMP table, gone when the connection closes: nothing to clean up.
 */

require_once __DIR__ . '/../path.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once FUNCTIONS . 'bootstrap.php';

use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Db\Select;

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FALHOU: {$label}\nesperado: " . var_export($expected, true) . "\nobtido:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "OK: {$label}\n";
}

function assertThrows(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (\Throwable) {
        echo "OK: {$label}\n";
        return;
    }
    fwrite(STDERR, "FALHOU: {$label} (não lançou erro)\n");
    exit(1);
}

$db = Connection::get()->adapter();
assertSame($db, Connection::get()->adapter(), 'adapter() devolve sempre o mesmo objeto');

// --- Select: SQL gerado ------------------------------------------------------------
$select = $db->select()
    ->from(['c' => 'customer_entity'], ['customer_id', 'mail' => 'email'])
    ->joinLeft(['g' => 'customer_group'], 'g.customer_group_id = c.group_id', ['group_name' => 'name'])
    ->where('c.website_id = ?', 3)
    ->where('c.email ILIKE ? OR c.firstname ILIKE ?', '%ana%')
    ->where('c.customer_id IN (?)', [1, 2])
    ->orWhere('c.is_active = ?', true)
    ->order('c.created_at DESC, customer_id')
    ->limitPage(3, 20);
assertSame(
    'SELECT "c"."customer_id", "c"."email" AS "mail", "g"."name" AS "group_name" FROM "customer_entity" AS "c"'
    . ' LEFT JOIN "customer_group" AS "g" ON g.customer_group_id = c.group_id'
    . ' WHERE (c.website_id = :p1) AND (c.email ILIKE :p2 OR c.firstname ILIKE :p3) AND (c.customer_id IN (:p4, :p5)) OR (c.is_active = :p6)'
    . ' ORDER BY "c"."created_at" DESC, "customer_id" LIMIT 20 OFFSET 40',
    (string) $select,
    'Select: from/joinLeft/where/orWhere/order/limitPage'
);
assertSame(['p1' => 3, 'p2' => '%ana%', 'p3' => '%ana%', 'p4' => 1, 'p5' => 2, 'p6' => 1], $select->getBind(), 'Select: valores vão como parâmetros (bool vira 0/1, array vira lista)');
assertSame(
    'SELECT "c"."mail", "c"."email" AS "mail" FROM "customer_entity" AS "c" LEFT JOIN "customer_group" AS "g" ON g.customer_group_id = c.group_id WHERE (c.website_id = :p1) AND (c.email ILIKE :p2 OR c.firstname ILIKE :p3) AND (c.customer_id IN (:p4, :p5)) OR (c.is_active = :p6)',
    (string) $select->reset(Select::COLUMNS)->reset(Select::ORDER)->reset(Select::LIMIT)->columns(['mail', 'mail' => 'email'], 'c'),
    'Select: reset e columns com correlação'
);
assertSame(
    'SELECT DISTINCT "t"."a", COUNT(*) AS "total", NOW() FROM "t" WHERE (x IS NULL) GROUP BY "t"."a" HAVING (COUNT(*) > :p1) ORDER BY random() FOR UPDATE SKIP LOCKED',
    (string) $db->select()->distinct()->from('t', ['a', 'total' => 'COUNT(*)', new Expr('NOW()')])
        ->where('x IS NULL')->group('t.a')->having('COUNT(*) > ?', 1)->order(new Expr('random()'))->forUpdate(true),
    'Select: distinct, Expr, group, having, forUpdate(skipLocked)'
);
assertSame('SELECT * FROM "t" WHERE (id IN (:p1))', (string) $db->select()->from('t', [])->where('id IN (?)', []), 'Select: IN com lista vazia vira IN (NULL), não SQL inválido');
assertThrows(fn () => $db->select()->from('t; DROP TABLE x'), 'nome de tabela com SQL é recusado');
assertThrows(fn () => $db->select()->from('t')->order('a; DROP TABLE x'), 'ordenação com SQL é recusada');
assertThrows(fn () => $db->select()->from('t', ['a b']), 'coluna com espaço é recusada');

// --- Adapter no banco (tabela temporária) ------------------------------------------
$db->exec('CREATE TEMP TABLE selftest_db (id SERIAL PRIMARY KEY, code VARCHAR(20) UNIQUE, qty INT NOT NULL DEFAULT 0, note TEXT, updated_at TIMESTAMP)');
$row = $db->insert('selftest_db', ['code' => 'a', 'qty' => 1]);
assertSame(['id' => $row['id'], 'code' => 'a', 'qty' => 1, 'note' => null], array_diff_key($row, ['updated_at' => 0]), 'insert devolve a linha gravada (RETURNING *)');
assertSame(2, $db->insertMultiple('selftest_db', [['code' => 'b', 'qty' => 2], ['code' => 'c', 'qty' => 3]]), 'insertMultiple grava várias linhas numa consulta');
assertSame(1, $db->insertOnConflict('selftest_db', ['code' => 'a', 'qty' => 10], ['code'], ['qty', 'updated_at' => new Expr('NOW()')]), 'insertOnConflict atualiza a linha existente');
assertSame(0, $db->insertOnConflict('selftest_db', ['code' => 'a', 'qty' => 99], ['code'], []), 'insertOnConflict com [] não faz nada (DO NOTHING)');
assertSame(['a' => 10, 'b' => 2, 'c' => 3], $db->fetchPairs($db->select()->from('selftest_db', ['code', 'qty'])->order('code')), 'fetchPairs');
assertSame(2, $db->update('selftest_db', ['note' => "O'Brien"], ['qty > ?' => 2, 'code <> ?' => 'z']), 'update com where em array (AND) devolve as linhas afetadas');
assertSame(["O'Brien", "O'Brien"], $db->fetchCol($db->select()->from('selftest_db', ['note'])->where('note IS NOT NULL')), 'fetchCol, e aspas no valor não quebram o SQL');
assertSame(1, $db->delete('selftest_db', ['code = ?' => 'b']), 'delete');
assertSame(2, $db->fetchOne($db->select()->from('selftest_db', ['n' => 'COUNT(*)'])), 'fetchOne');
assertSame(false, $db->fetchRow($db->select()->from('selftest_db')->where('code = ?', 'zzz')), 'fetchRow sem resultado devolve false');

// --- transações ------------------------------------------------------------------
$count = static fn (): int => (int) $db->fetchOne('SELECT COUNT(*) FROM selftest_db');
$db->transaction(static function ($db): void {
    $db->transaction(static fn ($db) => $db->insert('selftest_db', ['code' => 'd']));
    $db->insert('selftest_db', ['code' => 'e']);
});
assertSame([4, false], [$count(), $db->inTransaction()], 'transação aninhada: o commit de dentro não fecha a de fora');
assertThrows(static fn () => $db->transaction(static function ($db): void {
    $db->insert('selftest_db', ['code' => 'f']);
    $db->insert('selftest_db', ['code' => 'f']); // UNIQUE
}), 'erro dentro da transação é relançado');
assertSame([4, false], [$count(), $db->inTransaction()], 'erro dentro da transação desfaz tudo');
assertThrows(static fn () => $db->transaction(static function ($db): void {
    $db->insert('selftest_db', ['code' => 'g']);
    try {
        $db->transaction(static fn ($db) => throw new \RuntimeException('interna'));
    } catch (\RuntimeException) {
        // quem chamou engoliu o erro da transação interna
    }
}), 'transação interna desfeita impede o commit da de fora');
assertSame([4, false], [$count(), $db->inTransaction()], 'e a de fora é desfeita inteira');
$db->beginTransaction();
$db->prepare("INSERT INTO selftest_db (code) VALUES ('h')")->execute();
$db->rollBack();
assertSame(4, $count(), 'prepare() continua funcionando e respeita a transação do Adapter');
