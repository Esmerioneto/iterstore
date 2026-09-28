<?php

declare(strict_types=1);

namespace Iter\Catalog\View;

use Iter\Catalog\Model\Category;
use Iter\Catalog\Model\CategoryService;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\Form\FormSchemaGenerator;

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

        // a category can't go under itself or its own subcategories
        $exclude = $category ? $model->getResource()->getSubtreeIds($id) : [];

        return [
            'is_edit' => $category !== null,
            'category_id' => $id,
            'values' => array_merge(['parent_id' => (int) ($_GET['parent'] ?? 0), 'name' => '', 'url_key' => '', 'is_active' => 1, 'position' => 0], $category ?? [], $old ?? []),
            'parents' => array_filter($model->getResource()->getTree(), static fn ($c) => !in_array((int) $c['category_id'], $exclude, true)),
            'schema' => (new FormSchemaGenerator())->generate(Category::ENTITY_TYPE),
            'attribute_values' => array_merge(
                $id ? (new CategoryService())->getValues($id) : [],
                array_filter($old['attributes'] ?? [], static fn ($v) => !is_array($v) || array_is_list($v))
            ),
        ];
    }
}
