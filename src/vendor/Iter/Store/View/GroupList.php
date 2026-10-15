<?php

declare(strict_types=1);

namespace Iter\Store\View;

use Iter\Admin\View\Pager;
use Iter\Admin\View\Sort;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Group;

class GroupList implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'store/group-list';
    }

    public function getData(): array
    {
        $websiteId = (string) ($_GET['website_id'] ?? '');
        $groups = (new Group())->getList();
        $groups = ctype_digit($websiteId) ? array_filter($groups, static fn ($g) => (string) $g['website_id'] === $websiteId) : $groups;
        [$groups, $pager, $sort, $dir, $q] = Sort::slice($groups, ['website' => 'website_name', 'id' => 'group_id', 'code' => 'code', 'name' => 'name'], 'website', 'asc', ['code', 'name', 'website_name']);

        return [
            'groups' => $groups,
        'sort' => $sort,
            'dir' => $dir,
            'q' => $q,
            'website_id' => ctype_digit($websiteId) ? $websiteId : '',
            'websites' => array_column((new \Iter\Store\Model\Website())->getList(), 'name', 'website_id'),
            'pager' => $pager,
        ];
    }
}
