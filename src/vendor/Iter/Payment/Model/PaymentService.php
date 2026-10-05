<?php

declare(strict_types=1);

namespace Iter\Payment\Model;

use Iter\Core\Config;

/**
 * Payment options for a cart (COMMERCE-PLAN §5), switched on per website in Configurações → Pagamento.
 * Both are offline: the order waits for the admin to confirm the payment.
 * - pix: the store's Pix key (only offered once a key is set);
 * - offline: "combinar com a loja" (cash, card on pickup, deposit...).
 * ponytail: two methods in one class; a PaymentMethodInterface when Mercado Pago (online, with its API) arrives
 */
class PaymentService
{
    /**
     * @param array $cart CartService::view() (website_id)
     * @return array<string, array{code: string, label: string, instructions: ?string}> by code
     */
    public function methods(array $cart): array
    {
        $websiteId = (int) $cart['website_id'];
        $get = static fn (string $path) => trim((string) Config::get("payment/{$path}", $websiteId));
        $methods = [];
        if ($get('pix/active') && $get('pix/key') !== '') {
            $methods['pix'] = ['code' => 'pix', 'label' => 'Pix', 'instructions' => self::join(['Chave Pix: ' . $get('pix/key'), $get('pix/instructions')])];
        }
        if ($get('offline/active')) {
            $methods['offline'] = ['code' => 'offline', 'label' => $get('offline/label') ?: 'Combinar com a loja', 'instructions' => self::join([$get('offline/instructions')])];
        }

        return $methods;
    }

    private static function join(array $lines): ?string
    {
        return implode("\n", array_filter($lines, 'strlen')) ?: null;
    }
}
