<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeService;
use Iter\Eav\Model\ResourceModel\Attribute as AttributeResource;

class AttributeList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'eav/attribute-list';
    }

    public function getData(): array
    {
        $filters = [];
        foreach (['entity_type', 'input_type', 'is_system', 'q'] as $key) {
            $filters[$key] = trim((string) ($_GET[$key] ?? ''));
        }
        $resource = (new Attribute())->getResource();
        [$sort, $dir] = Sort::read(array_keys(AttributeResource::SORTS), 'entity');
        [$result, $pager] = Pager::search(static fn (int $page, int $limit) => $resource->search($filters, $page, $limit, $sort, $dir));

        return [
            'attributes' => $result['items'],
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'pager' => $pager,
            'total' => $result['total'],
            'entity_types' => $resource->getEntityTypes(),
            'input_types' => InputTypeRegistry::all(),
            'can_manage' => AttributeService::canManage(),
        ];
    }
}
