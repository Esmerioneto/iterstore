<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\CategoryBannerService;
use Iter\Catalog\Model\CategoryService;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Form\FormSchemaGenerator;
use Iter\Store\Model\Website;

class CategoryForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'catalog/category-form';
    }

    public function getData(): array
    {
        $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
        $model = new Category();
        $category = $id && $model->load($id)->getId() ? $model->getData() : null;

        $old = $_SESSION['iter_old_input'] ?? null;
        unset($_SESSION['iter_old_input']);

        // a category can't go under itself or its own subcategories, nor under another website's
        $exclude = $category ? $model->getResource()->getSubtreeIds($id) : [];
        $tree = array_filter($model->getResource()->getTree(), static fn ($c) => !in_array((int) $c['category_id'], $exclude, true)
            && ($category === null || (int) $c['website_id'] === (int) $category['website_id']));

        return [
            'is_edit' => $category !== null,
            'category_id' => $id,
            'values' => array_merge(['website_id' => (new Website())->getResource()->getDefaultId(), 'parent_id' => (int) ($_GET['parent'] ?? 0), 'name' => '', 'url_key' => '', 'is_active' => 1, 'position' => 0], $category ?? [], $old ?? []),
            'parents' => $tree,
            'websites' => array_column((new Website())->getList(), 'name', 'website_id'),
            'schema' => (new FormSchemaGenerator())->generate(Category::ENTITY_TYPE),
            'banners' => $category ? (new CategoryBannerService())->getList($id) : [],
            'banner_limit' => CategoryBannerService::LIMIT,
            'banner_accept' => array_keys(InputTypeRegistry::get('image')['accept']),
            // up to LIMIT files in one post: each one gets its share of post_max_size (the browser shrinks the bigger ones)
            'banner_max_bytes' => min(Config::uploadMaxBytes(), intdiv(ini_parse_quantity((string) ini_get('post_max_size')) ?: PHP_INT_MAX, CategoryBannerService::LIMIT + 1)),
            'attribute_values' => array_merge(
                $id ? (new CategoryService())->getValues($id) : [],
                array_filter($old['attributes'] ?? [], static fn ($v) => !is_array($v) || array_is_list($v))
            ),
        ];
    }
}
