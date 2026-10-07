<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Catalog\Model\MobileService;
use Iter\Core\Config;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;

/** Catálogo → Mobile: the order of the app's home (MOBILE-PLAN) */
class Mobile
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::mobile';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Mobile'],
        ]);

        echo Layout::renderAdmin('admin_mobile_index');
    }

    /** the whole screen at once: every category in the posted order (PHP keeps the keys' order) */
    public function Save(): void
    {
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $categories = [];
        foreach ((array) ($_POST['categories'] ?? []) as $id => $c) {
            $c = (array) $c;
            $categories[] = [
                'id' => (int) $id,
                'visible' => !empty($c['visible']),
                'per_category' => (int) ($c['per_category'] ?? MobileService::DEFAULT_PER_CATEGORY),
                'product_ids' => array_map('intval', (array) ($c['product_ids'] ?? [])),
            ];
        }
        try {
            if (!Config::websiteExists($websiteId)) {
                throw new AttributeException('WEBSITE_NOT_FOUND', 'Website não encontrado.');
            }
            $service = new MobileService();
            $service->save($websiteId, $categories);
            $service->setEnabled($websiteId, !empty($_POST['enabled']));
            Messages::addSuccess('Ordem do app salva.');
        } catch (AttributeException $e) {
            Messages::addError($e->getMessage()); // ponytail: the screen reloads as saved; its own limits (min/max) stop most errors first
        }
        header('Location: /admin/mobile/index?website_id=' . $websiteId);
        exit;
    }
}
