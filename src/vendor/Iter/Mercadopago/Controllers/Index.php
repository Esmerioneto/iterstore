<?php

declare(strict_types=1);

namespace Iter\Mercadopago\Controllers;

use Iter\Core\Db\Connection;
use Iter\Core\Log;
use Iter\Mercadopago\Model\MercadopagoService;
use Iter\Sales\Model\SalesException;

/**
 * POST /mercadopago/webhook: the Mercado Pago's notice that an order (a charge) changed (the only route
 * outside GraphQL). The body only says which one: it is read back from the API (MercadopagoService::sync).
 * With the website's secret saved, an x-signature that doesn't match is refused.
 */
class Index
{
    public function Webhook(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        // Webhooks, topic Order: ?data.id=ORD... (PHP reads data_id) and type=order
        $type = (string) ($_GET['type'] ?? $_GET['topic'] ?? $body['type'] ?? '');
        $id = (string) ($_GET['data_id'] ?? $body['data']['id'] ?? $_GET['id'] ?? '');
        if ($type !== 'order' || !preg_match('/^[A-Za-z0-9]{1,64}$/', $id)) {
            http_response_code(200); // other topics: nothing to do, and no retries
            return;
        }

        $db = Connection::get()->adapter();
        $websiteId = $db->fetchOne($db->select()->from(['p' => 'mercadopago_payment'], [])
            ->join(['o' => 'sales_order'], 'o.order_id = p.order_id', ['website_id'])->where('p.external_id = ?', $id));
        if ($websiteId === false) {
            http_response_code(200); // not one of this store's (or another system on the same account)
            return;
        }
        $secret = MercadopagoService::webhookSecret((int) $websiteId);
        if ($secret !== '' && !MercadopagoService::validSignature((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''), (string) ($_GET['data_id'] ?? $id), $secret)) {
            Log::alert("Mercado Pago: aviso do pagamento #{$id} com assinatura inválida");
            http_response_code(401);
            return;
        }

        try {
            (new MercadopagoService())->sync($id);
            http_response_code(200);
        } catch (SalesException $e) {
            Log::error($e->getMessage());
            http_response_code(503); // the Mercado Pago tries again
        }
    }
}
