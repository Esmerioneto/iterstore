<?php

declare(strict_types=1);

namespace Iter\Eav\View;

use Iter\Core\View\ViewComponentInterface;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\Attribute;
use Iter\Eav\Model\AttributeService;

class AttributeList implements ViewComponentInterface
{
    private const PER_PAGE = 20;

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
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $resource = (new Attribute())->getResource();
        $result = $resource->search($filters, $page, self::PER_PAGE);

        return [
            'attributes' => $result['items'],
            'filters' => $filters,
            'page' => $page,
            'total' => $result['total'],
            'total_pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'entity_types' => $resource->getEntityTypes(),
            'input_types' => InputTypeRegistry::all(),
            'can_manage' => AttributeService::canManage(),
        ];
    }
}
