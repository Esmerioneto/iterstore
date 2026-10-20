<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\Model\ValueService;
use Iter\Log\Model\OperationLog;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Catálogo → Produtos → Importar custos (REPORT-PLAN delivery 5): a .csv or .xlsx with the SKU and Custo
 * columns sets the cost of many products at once. All or nothing: one bad line and nothing is saved.
 * An empty cost keeps the product's; "0" is a real cost of zero.
 */
final class CostImport
{
    public const MAX_LINES = 20000;

    /** @var string[] what went wrong, "Linha 3: ..." */
    public array $errors = [];

    /**
     * @param string $path the uploaded file
     * @param string $name its original name (the extension says the format)
     * @return array{changed: int, same: int, empty: int}|null null when $errors has something
     */
    public function run(string $path, string $name): ?array
    {
        $lines = $this->read($path, strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        $costs = $this->errors ? [] : $this->costs($lines);
        if ($this->errors) {
            return null;
        }
        $values = new ValueService();
        $current = $values->loadMany(Product::ENTITY_TYPE, array_keys($costs), [Product::COST]);
        $result = ['changed' => 0, 'same' => 0, 'empty' => count($lines) - count($costs)];
        Connection::get()->adapter()->transaction(function () use ($costs, $current, $values, &$result): void {
            foreach ($costs as $id => [$cost, $label]) {
                $before = (string) ($current[$id][Product::COST] ?? '');
                if ($before !== '' && (float) $before === (float) $cost) {
                    $result['same']++;
                    continue;
                }
                $values->save(Product::ENTITY_TYPE, $id, [Product::COST => $cost]);
                OperationLog::active() && OperationLog::record('update', 'product', $id, $label, ['Custo' => $before === '' ? '' : self::trim($before)], ['Custo' => self::trim($cost)]);
                $result['changed']++;
            }
        });

        return $result;
    }

    /** every product that can have a cost (not a configurable parent: the cost goes on each variant), with its cost now, for the model file */
    public function template(): array
    {
        $db = Connection::get()->adapter();
        $products = $db->fetchAll("SELECT product_id, sku, name FROM catalog_product WHERE type <> 'configurable' ORDER BY sku");
        $costs = (new ValueService())->loadMany(Product::ENTITY_TYPE, array_column($products, 'product_id'), [Product::COST]);

        return array_map(static fn (array $p) => [$p['sku'], $p['name'], isset($costs[$p['product_id']][Product::COST]) ? str_replace('.', ',', self::trim((string) $costs[$p['product_id']][Product::COST])) : ''], $products);
    }

    /** "12,50", "1.234,56", "R$ 9,9", "12.5" or a spreadsheet number: the decimal as "1234.56"; null when it isn't one */
    public static function parse(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? (string) round((float) $value, 4) : null;
        }
        $value = str_replace(['R$', ' ', "\u{a0}"], '', (string) $value);
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return preg_match('/^\d+(\.\d{1,4})?$/', $value) ? $value : null;
    }

    /** @return array<int, array{0: mixed, 1: mixed}> line number => [sku, cost], without the header and blank lines */
    private function read(string $path, string $extension): array
    {
        $rows = match ($extension) {
            'csv', 'txt' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            default => null,
        };
        if ($rows === null) {
            $this->errors[] = 'Envie um arquivo .csv ou .xlsx.';

            return [];
        }
        $header = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $sku = array_search('sku', $header, true);
        $cost = array_search('custo', $header, true);
        if ($sku === false || $cost === false) {
            $this->errors[] = 'A primeira linha precisa ter as colunas "SKU" e "Custo" (baixe o modelo).';

            return [];
        }
        $lines = [];
        foreach ($rows as $i => $row) {
            if (trim((string) ($row[$sku] ?? '')) !== '' || trim((string) ($row[$cost] ?? '')) !== '') {
                $lines[$i + 2] = [$row[$sku] ?? '', $row[$cost] ?? ''];
            }
        }
        if (count($lines) > self::MAX_LINES) {
            $this->errors[] = 'O arquivo tem ' . count($lines) . ' linhas; o máximo é ' . self::MAX_LINES . '. Divida em mais de um arquivo.';
        } elseif (!$lines) {
            $this->errors[] = 'O arquivo não tem nenhuma linha depois do cabeçalho.';
        }

        return $lines;
    }

    /** @return array<int, array{0: string, 1: string}> product id => [cost, log label], only the lines with a cost */
    private function costs(array $lines): array
    {
        $ids = [];
        foreach (Connection::get()->adapter()->fetchAll('SELECT product_id, sku, name, type FROM catalog_product') as $p) {
            $ids[mb_strtolower($p['sku'])] = $p;
        }
        $costs = [];
        $seen = [];
        foreach ($lines as $line => [$sku, $cost]) {
            $sku = trim((string) $sku);
            $product = $ids[mb_strtolower($sku)] ?? null;
            $value = trim((string) $cost) === '' ? '' : self::parse($cost);
            $error = match (true) {
                $sku === '' => 'sem SKU.',
                $product === null => "o SKU \"{$sku}\" não existe.",
                isset($seen[$product['product_id']]) => "o SKU \"{$sku}\" já está na linha {$seen[$product['product_id']]}.",
                $product['type'] === 'configurable' => "\"{$sku}\" tem variações: o custo vai em cada variação.",
                $value === null => "custo inválido \"{$cost}\" (use um número, como 12,50).",
                default => null,
            };
            if ($error !== null) {
                $this->errors[] = "Linha {$line}: {$error}";
                continue;
            }
            $seen[$product['product_id']] = $line;
            if ($value !== '') {
                $costs[(int) $product['product_id']] = [$value, $product['sku'] . ' — ' . $product['name']];
            }
        }

        return $costs;
    }

    /** CSV as Excel saves it in Brazil (";") or with ","; the BOM is dropped */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn ($v) => mb_check_encoding((string) $v, 'UTF-8') ? $v : mb_convert_encoding((string) $v, 'UTF-8', 'Windows-1252'), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** the first sheet; null when it isn't a valid .xlsx */
    private function xlsx(string $path): ?array
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true)); // the line numbers in the errors match the sheet
        try {
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                break;
            }
        } catch (\Throwable) {
            return null;
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
