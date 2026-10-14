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
Connection::get()->connect()->exec('CREATE TEMP TABLE selftest_db (id SERIAL PRIMARY KEY, code VARCHAR(20) UNIQUE, qty INT NOT NULL DEFAULT 0, note TEXT, updated_at TIMESTAMP)');
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
$db->transaction(static function ($db): void {
    $db->insert('selftest_db', ['code' => 'i']);
    try {
        $db->savepoint(static fn ($db) => $db->insert('selftest_db', ['code' => 'i'])); // UNIQUE
    } catch (\PDOException) {
        // o log de operações engole a própria falha
    }
    $db->insert('selftest_db', ['code' => 'j']);
});
assertSame([6, false], [$count(), $db->inTransaction()], 'savepoint: a falha desfaz só o que estava dentro dele, e a transação de fora continua');
$db->delete('selftest_db', ['code IN (?)' => ['i', 'j']]);
assertSame([false, false, false], [method_exists($db, 'prepare'), method_exists($db, 'query'), method_exists($db, 'exec')], 'o Adapter não repassa SQL solto (DB-PLAN fase 6)');
$db->beginTransaction();
$inserted = [$db->insertOnConflict('selftest_db', ['code' => 'a'], []), $db->insertOnConflict('selftest_db', ['code' => 'h'], [])];
$db->rollBack();
assertSame([[0, 1], 4], [$inserted, $count()], 'insertOnConflict sem colunas: DO NOTHING em qualquer chave única (a existente fica, a nova entra)');

// --- no loose SQL outside Core/Db and the migrations (DB-PLAN phase 6) ----------
// queries go through the Adapter (select, insert, update, delete, fetch*); a migration gets its own PDO
$loose = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SRC, FilesystemIterator::SKIP_DOTS)) as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if ($file->getExtension() !== 'php' || str_contains($path, '/Setup/') || str_contains($path, '/Core/Db/')) {
        continue;
    }
    foreach (file($path) as $n => $line) {
        if (preg_match('/->(prepare|query|exec)\(|->connect\(\)/', $line)) {
            $loose[] = substr($path, strlen(SRC)) . ':' . ($n + 1);
        }
    }
}
assertSame([], $loose, 'nenhum prepare/query/exec/connect() fora de Core/Db e das migrações');

// --- Collection ------------------------------------------------------------------
$attributes = (new Iter\Eav\Model\ResourceModel\Attribute\Collection())
    ->addFieldToFilter('et.code', 'product')
    ->addFieldToFilter(['code', 'label'], [['in' => ['price', 'description']], ['ilike' => 'zzz%']])
    ->addFieldToFilter('sort_order', ['gteq' => 0, 'lt' => 100000])
    ->addFieldToFilter('input_type', ['notnull' => true])
    ->setOrder('code', 'ASC');
assertSame(
    'SELECT "main_table".*, "et"."code" AS "entity_type", "et"."label" AS "entity_type_label" FROM "eav_attribute" AS "main_table"'
    . ' JOIN "eav_entity_type" AS "et" ON et.entity_type_id = main_table.entity_type_id'
    . ' WHERE ("et"."code" = :p1) AND (("main_table"."code" IN (:p2, :p3)) OR ("main_table"."label" ILIKE :p4))'
    . ' AND ("main_table"."sort_order" >= :p5 AND "main_table"."sort_order" < :p6) AND ("main_table"."input_type" IS NOT NULL)'
    . ' ORDER BY "main_table"."code" ASC',
    (string) $attributes->getSelect(),
    'Collection: addFieldToFilter (valor, lista de campos em OR, várias condições em AND) e setOrder'
);
assertSame([2, ['description', 'price']], [$attributes->getSize(), array_column($attributes->getData(), 'code')], 'Collection: getSize e getData');
$page = (new Iter\Eav\Model\ResourceModel\Attribute\Collection())->addFieldToFilter('code', ['in' => ['price', 'description']])
    ->setOrder('code', 'ASC')->setPageSize(1)->setCurPage(2);
$items = $page->getItems();
assertSame([2, 1, Iter\Eav\Model\Attribute::class, 'price'], [$page->getSize(), count($page), $items[0]::class, $items[0]->getData('code')], 'Collection: página, getSize ignora a página, getItems devolve models');
assertSame(0, (new Iter\Eav\Model\ResourceModel\Attribute\Collection())->addFieldToFilter('code', ['in' => []])->getSize(), 'Collection: in com lista vazia não devolve nada');
assertThrows(fn () => (new Iter\Eav\Model\ResourceModel\Attribute\Collection())->addFieldToFilter('code', ['regex' => 'x']), 'Collection: condição desconhecida é recusada');

// --- EAV collection (DB-PLAN phase 5) --------------------------------------------
// its own products and attributes in a transaction undone at the end: nothing to clean up
$db->beginTransaction();
$productType = (int) $db->fetchOne("SELECT entity_type_id FROM eav_entity_type WHERE code = 'product'");
$attr = static fn (string $code, string $input, string $storage) => (int) $db->insert('eav_attribute', ['entity_type_id' => $productType, 'code' => $code,
    'label' => 'Dbselftest ' . $code, 'input_type' => $input, 'storage_type' => $storage])['attribute_id'];
[$cor, $tags, $peso] = [$attr('dbst_cor', 'text', 'varchar'), $attr('dbst_tags', 'multiselect', 'text'), $attr('dbst_peso', 'number', 'decimal')];
$set = (int) $db->fetchOne('SELECT attribute_set_id FROM eav_attribute_set WHERE entity_type_id = ? ORDER BY attribute_set_id LIMIT 1', [$productType]);
$ids = [];
foreach (['a' => ['azul', '["x","y"]', '1.5000'], 'b' => ['verde', '["y"]', '3.0000'], 'c' => [null, null, '2.2500'], 'd' => [null, null, null]] as $sku => [$c, $t, $p]) {
    $id = $ids[$sku] = (int) $db->insert('catalog_product', ['attribute_set_id' => $set, 'sku' => 'dbselftest-' . $sku, 'name' => 'Dbst ' . $sku])['product_id'];
    $c === null || $db->insert('eav_value_varchar', ['attribute_id' => $cor, 'entity_id' => $id, 'value' => $c]);
    $t === null || $db->insert('eav_value_text', ['attribute_id' => $tags, 'entity_id' => $id, 'value' => $t]);
    $p === null || $db->insert('eav_value_decimal', ['attribute_id' => $peso, 'entity_id' => $id, 'value' => $p]);
}
$entities = static fn () => (new class extends Iter\Eav\Model\ResourceModel\AbstractEntityCollection {
    protected function _construct(): void
    {
        $this->init(Iter\Catalog\Model\Product::class, Iter\Catalog\Model\ResourceModel\Product::class);
    }

    protected function entityType(): string
    {
        return 'product';
    }
})->addFieldToFilter('sku', ['like' => 'dbselftest-%']);
$skus = static fn ($c) => array_map(static fn ($r) => substr($r['sku'], -1), $c->getData());

$selected = $entities()->addAttributeToSelect(['dbst_cor', 'dbst_tags'])->addAttributeToSelect('dbst_peso')->setOrder('sku', 'ASC');
(new Iter\Eav\Model\Attribute())->getResource()->getEntityTypes(); // read once per process
Iter\Core\Db\Adapter::$queryCount = 0;
$rows = $selected->getData();
assertSame([['dbst_cor' => 'azul', 'dbst_tags' => ['x', 'y'], 'dbst_peso' => '1.5'], [], 2], [$rows[0]['attributes'], $rows[3]['attributes'], Iter\Core\Db\Adapter::$queryCount],
    'EAV: addAttributeToSelect traz os valores da página numa consulta só, convertidos como no load()');
assertSame(['dbst_peso' => '3'], $entities()->addAttributeToSelect('dbst_peso')->addFieldToFilter('sku', 'dbselftest-b')->getData()[0]['attributes'], 'EAV: só os atributos pedidos');
assertSame(true, isset($entities()->addAttributeToSelect('*')->setOrder('sku', 'ASC')->getData()[0]['attributes']['dbst_tags']), "EAV: '*' traz todos");
assertSame(false, array_key_exists('attributes', $entities()->getData()[0]), 'EAV: sem addAttributeToSelect, a linha não ganha attributes');
assertSame([
    ['b', 'c'], ['a'], ['c', 'd'], ['a', 'b', 'c'], ['a', 'b'], ['b'], ['b'], ['a', 'b'],
], [
    $skus($entities()->addAttributeToFilter('dbst_peso', ['gt' => 2])->setOrder('sku', 'ASC')),
    $skus($entities()->addAttributeToFilter('dbst_cor', 'azul')),
    $skus($entities()->addAttributeToFilter('dbst_cor', ['null' => true])->setOrder('sku', 'ASC')),
    $skus($entities()->addAttributeToFilter('dbst_peso', ['notnull' => true])->setOrder('sku', 'ASC')),
    $skus($entities()->addAttributeToFilter('dbst_cor', ['in' => ['azul', 'verde']])->setOrder('sku', 'ASC')),
    $skus($entities()->addAttributeToFilter('dbst_cor', ['neq' => 'azul'])),
    $skus($entities()->addAttributeToFilter('dbst_peso', ['gteq' => 2])->addAttributeToFilter('dbst_cor', ['like' => 'ver%'])),
    $skus($entities()->addAttributeToFilter('dbst_tags', ['in' => ['y', 'z']])->setOrder('sku', 'ASC')),
], 'EAV: addAttributeToFilter (gt, valor, null, notnull, in, neq sem os vazios, várias em AND, lista "tem um de")');
assertSame([['a', 'c', 'b', 'd'], ['b', 'c', 'a', 'd'], ['a', 'b', 'c', 'd']], [
    $skus($entities()->addAttributeToSort('dbst_peso')),
    $skus($entities()->addAttributeToSort('dbst_peso', 'DESC')),
    $skus($entities()->addAttributeToSort('dbst_cor')->addAttributeToSort('dbst_peso', 'DESC')), // c and d have no color: the weight decides
], 'EAV: addAttributeToSort nos dois sentidos e por duas chaves; sem valor fica por último');
assertThrows(fn () => $entities()->addAttributeToFilter('dbst_nada', 1), 'EAV: atributo desconhecido é recusado');
assertSame(['dbst_cor' => 'verde', 'dbst_tags' => ['y'], 'dbst_peso' => '3'], array_intersect_key((new Iter\Eav\Model\ValueService())->load('product', $ids['b']), array_flip(['dbst_cor', 'dbst_tags', 'dbst_peso'])),
    'EAV: load() de um item continua igual (agora via loadMany)');
$db->rollBack();
