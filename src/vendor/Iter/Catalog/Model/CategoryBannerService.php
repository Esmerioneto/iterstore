<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Db\Connection;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * A category's banners (BANNER-PLAN): one image, or a carousel of up to LIMIT, in order.
 * Files go under ValueService::mediaDir() . 'category-banner/', with the same types as an
 * Imagem attribute. Like ValueService, validate() checks everything first and write() copies
 * the new files before the rows change, deleting the removed ones only after the commit.
 */
class CategoryBannerService
{
    public const LIMIT = 5;
    private const FOLDER = 'category-banner';

    /** @return array<int, array{banner_id: int, file: string, url: string, position: int}> in order */
    public function getList(int $categoryId): array
    {
        return $this->lists([$categoryId])[$categoryId] ?? [];
    }

    /**
     * Every banner of the given categories (null = all) in one query.
     *
     * @return array<int, array<int, array{banner_id: int, file: string, url: string, position: int}>> category_id => banners in order
     */
    public function lists(?array $categoryIds = null): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from('catalog_category_banner', ['banner_id', 'category_id', 'file', 'position'])->order(['category_id', 'position', 'banner_id']);
        if ($categoryIds !== null) {
            $select->where('category_id IN (?)', array_map('intval', $categoryIds) ?: [0]);
        }
        $lists = [];
        foreach ($db->fetchAll($select) as $row) {
            $lists[(int) $row['category_id']][] = ['banner_id' => (int) $row['banner_id'], 'file' => $row['file'], 'url' => ValueService::MEDIA_URL . $row['file'], 'position' => (int) $row['position']];
        }

        return $lists;
    }

    /**
     * Checks the new list of a category before anything is written.
     *
     * @param int[] $keep the banners that stay, in their new order (the others are removed)
     * @param array<int, array{path: string, name: string}> $add uploads (ValueService::uploaded()), appended after $keep
     * @return array{keep: int[], add: array<int, array{file: string, upload: string}>, remove: string[]} what write() takes
     */
    public function validate(?int $categoryId, array $keep, array $add): array
    {
        $current = array_column($categoryId ? $this->getList($categoryId) : [], 'file', 'banner_id');
        $keep = array_values(array_unique(array_map('intval', $keep)));
        if (array_diff($keep, array_keys($current))) {
            throw new AttributeException('BANNER_NOT_FOUND', 'Banner não encontrado nesta categoria.');
        }
        if (count($keep) + count($add) > self::LIMIT) {
            throw new AttributeException('BANNER_LIMIT', 'Uma categoria tem no máximo ' . self::LIMIT . ' banners.');
        }
        $accept = InputTypeRegistry::get('image')['accept'];

        return [
            'keep' => $keep,
            'add' => array_map(static fn (array $u) => ['file' => ValueService::storedName(self::FOLDER, $u, $accept, 'Banners'), 'upload' => (string) $u['path']], array_values($add)),
            'remove' => array_values(array_diff_key($current, array_flip($keep))),
        ];
    }

    /** @param array $list from validate() */
    public function write(int $categoryId, array $list): void
    {
        $copied = [];
        try {
            foreach ($list['add'] as $new) {
                $target = ValueService::mediaDir() . $new['file'];
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Não foi possível criar a pasta ' . dirname($target));
                }
                if (!copy($new['upload'], $target)) {
                    throw new \RuntimeException("Não foi possível gravar {$target}");
                }
                $copied[] = $target;
            }
            Connection::get()->adapter()->transaction(static function ($db) use ($categoryId, $list): void {
                $db->delete('catalog_category_banner', $list['keep'] ? ['category_id = ?' => $categoryId, 'banner_id NOT IN (?)' => $list['keep']] : ['category_id = ?' => $categoryId]);
                foreach ($list['keep'] as $position => $id) {
                    $db->update('catalog_category_banner', ['position' => $position], ['banner_id = ?' => $id]);
                }
                $db->insertMultiple('catalog_category_banner', array_map(static fn (array $new, int $i) => ['category_id' => $categoryId, 'file' => $new['file'], 'position' => count($list['keep']) + $i],
                    $list['add'], array_keys($list['add'])));
            });
        } catch (\Throwable $e) {
            array_map('unlink', $copied);
            throw $e;
        }
        $this->unlink($list['remove']);
    }

    public function save(int $categoryId, array $keep, array $add): void
    {
        $this->write($categoryId, $this->validate($categoryId, $keep, $add));
    }

    /** @param array<int, string|array{file: string}> $files file names or getList() rows */
    public function unlink(array $files): void
    {
        foreach ($files as $file) {
            @unlink(ValueService::mediaDir() . (is_array($file) ? $file['file'] : $file));
        }
    }
}
