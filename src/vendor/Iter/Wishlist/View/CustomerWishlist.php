<?php

declare(strict_types=1);

namespace Iter\Wishlist\View;

use Iter\Admin\Auth;
use Iter\Core\View\ViewComponentInterface;
use Iter\Wishlist\Model\WishlistService;

/** "Favoritos" under the customer's form (editing only), read-only (WISHLIST-PLAN §1.6) */
final class CustomerWishlist implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'wishlist/customer-wishlist';
    }

    public function getData(): array
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0 || !Auth::isAllowed('Iter_Customer::customer')) {
            return ['items' => null];
        }

        return ['items' => (new WishlistService())->adminList($id), 'can_edit_product' => Auth::isAllowed('Iter_Catalog::product')];
    }
}
