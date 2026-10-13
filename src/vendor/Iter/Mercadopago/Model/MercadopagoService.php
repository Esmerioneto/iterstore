<?php

declare(strict_types=1);

namespace Iter\Mercadopago\Model;

use Iter\Core\Config;
use Iter\Core\Crypt;
use Iter\Core\Db\Connection;
use Iter\Core\Log;
use Iter\Sales\Model\OrderService;
use Iter\Sales\Model\SalesException;

/**
 * Mercado Pago's Checkout Transparente via Orders (MERCADOPAGO-PLAN), Orders API (/v1/orders) over curl:
 * applications created as "Checkout Transparente via Orders" get 401 from /v1/payments.
 * placeOrder creates the order (pending_payment) as with any method; pay() then charges it: Pix gives
 * the QR code, a card is charged with the token the app made with the SDK (the card number never
 * comes here). The webhook and bin/mercadopago-sync.php read the payment back from the API (a
 * notification's body is never trusted) and an approved one confirms the order by itself.
 * Test or production depends only on the credentials saved in Configurações → Pagamento.
 */
class MercadopagoService
{
    /** the cart's payment codes and their default labels */
    public const METHODS = ['mercadopago_pix' => 'Pix', 'mercadopago_credit' => 'Cartão de crédito', 'mercadopago_debit' => 'Cartão de débito'];

    public const STATUS_LABELS = [
        'pending' => 'Aguardando pagamento',
        'in_process' => 'Em análise',
        'authorized' => 'Autorizado',
        'approved' => 'Aprovado',
        'rejected' => 'Recusado',
        'cancelled' => 'Cancelado',
        'refunded' => 'Estornado',
        'charged_back' => 'Contestado (chargeback)',
        'in_mediation' => 'Em disputa',
    ];

    /** order status (and status_detail) of the Orders API => this module's status, the Payments API's names */
    private const ORDER_STATUS = [
        'created' => 'pending',
        'action_required' => 'pending',
        'processing' => 'in_process',
        'processed' => 'approved',
        'failed' => 'rejected',
        'canceled' => 'cancelled',
        'cancelled' => 'cancelled',
        'expired' => 'cancelled',
        'refunded' => 'refunded',
        'charged_back' => 'charged_back',
    ];

    /** still may change by itself: the cron reads these back */
    private const OPEN = ['pending', 'in_process', 'authorized'];

    private const API = 'https://api.mercadopago.com';

    /**
     * @param ?\Closure $http fn (string $method, string $path, ?array $body): array{0: int, 1: array} in place
     *                        of the network (the selftest's fake Mercado Pago)
     */
    public function __construct(private readonly ?\Closure $http = null)
    {
    }

    /** @return array<string, array{code: string, label: string, instructions: null}> the website's methods switched on, once an Access Token is saved */
    public function methods(int $websiteId): array
    {
        if (self::token($websiteId) === '') {
            return [];
        }
        $methods = [];
        foreach (self::METHODS as $code => $label) {
            if (Config::get("payment/{$code}/active", $websiteId)) {
                $methods[$code] = ['code' => $code, 'label' => $label, 'instructions' => null];
            }
        }

        return $methods;
    }

    public static function publicKey(int $websiteId): string
    {
        return trim((string) Config::get('payment/mercadopago/public_key', $websiteId));
    }

    public static function maxInstallments(int $websiteId): int
    {
        return min(12, max(1, (int) Config::get('payment/mercadopago_credit/max_installments', $websiteId)));
    }

    /**
     * Charges an order waiting for payment by its Mercado Pago method. Pix: a still valid unpaid QR code is
     * given back instead of a new one. Card: a declined one may be tried again with a new token; the same
     * token sent twice (double click) gets the same payment (idempotency key).
     *
     * @param ?array{token: string, payment_method_id: string, installments?: int, issuer_id?: string} $card from the SDK
     * @return array the payment (get())
     * @throws SalesException PAYMENT_METHOD_INVALID, ORDER_STATUS_INVALID, CARD_REQUIRED, INSTALLMENTS_INVALID, PAYMENT_FAILED, PAYMENT_UNAVAILABLE
     */
    public function pay(int $orderId, ?array $card = null): array
    {
        $order = (new OrderService())->get($orderId);
        $method = (string) $order['payment_method'];
        if (!isset(self::METHODS[$method])) {
            throw new SalesException('PAYMENT_METHOD_INVALID', 'Este pedido não é pago pelo Mercado Pago.');
        }
        if ($order['status'] !== 'pending_payment') {
            throw new SalesException('ORDER_STATUS_INVALID', 'Este pedido não está aguardando pagamento.');
        }
        $websiteId = (int) $order['website_id'];
        $taxvat = (string) $order['taxvat'];
        $amount = number_format((float) $order['grand_total'], 2, '.', '');
        $body = [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => $amount,
            'description' => "Pedido {$order['increment_id']}",
            'external_reference' => $order['increment_id'],
            'payer' => [
                'email' => $order['email'],
                'first_name' => $order['firstname'],
                'last_name' => $order['lastname'],
                'identification' => ['type' => strlen($taxvat) === 14 ? 'CNPJ' : 'CPF', 'number' => $taxvat],
            ],
        ];
        // the Orders API refuses notification_url: the webhook (topic Order) is set in Suas integrações; the cron reads back the rest

        if ($method === 'mercadopago_pix') {
            $db = Connection::get()->adapter();
            $open = $db->fetchOne($db->select()->from('mercadopago_payment', ['external_id'])->where('order_id = ?', $orderId)
                ->where("status = 'pending'")->where('expires_at IS NULL OR expires_at > NOW()')->order('payment_id DESC')->limit(1));
            if ($open !== false) {
                return $this->get((string) $open);
            }
            $payment = ['amount' => $amount, 'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer']];
            // the QR code lasts as long as the order waits (Mercado Pago accepts 30 minutes to 30 days)
            $hours = (int) Config::get('payment/mercadopago_pix/cancel_hours', $websiteId);
            if ($hours > 0) {
                $payment['expiration_time'] = 'PT' . min($hours, 720) . 'H';
            }
            $key = bin2hex(random_bytes(16));
        } else {
            $token = trim((string) ($card['token'] ?? ''));
            $brand = trim((string) ($card['payment_method_id'] ?? ''));
            if ($token === '' || $brand === '') {
                throw new SalesException('CARD_REQUIRED', 'Envie o token e a bandeira do cartão gerados pelo SDK do Mercado Pago.');
            }
            $installments = $method === 'mercadopago_debit' ? 1 : (int) ($card['installments'] ?? 1);
            if ($installments < 1 || $installments > self::maxInstallments($websiteId)) {
                throw new SalesException('INSTALLMENTS_INVALID', sprintf('Escolha de 1 a %d parcelas.', self::maxInstallments($websiteId)));
            }
            $payment = ['amount' => $amount, 'payment_method' => [
                'id' => $brand,
                'type' => $method === 'mercadopago_debit' ? 'debit_card' : 'credit_card',
                'token' => $token,
                'installments' => $installments,
            ]];
            // ponytail: no 3DS (transaction_security), the app has no WebView for the challenge yet
            $key = hash('sha256', $token);
            // the digits for the admin; a token that can't be read doesn't stop the charge
            try {
                [$tokenStatus, $cardToken] = $this->call('GET', '/v1/card_tokens/' . rawurlencode($token), $websiteId);
            } catch (SalesException) {
                [$tokenStatus, $cardToken] = [0, []];
            }
            $digits = $tokenStatus > 0 && $tokenStatus < 400 ? array_filter([
                'card_first_six' => preg_match('/^\d{6}$/', (string) ($cardToken['first_six_digits'] ?? '')) ? $cardToken['first_six_digits'] : null,
                'card_last_four' => preg_match('/^\d{4}$/', (string) ($cardToken['last_four_digits'] ?? '')) ? $cardToken['last_four_digits'] : null,
            ]) : [];
            $digits['card_brand'] = $brand; // the one sent, in case the answer leaves it out
        }
        $body['transactions'] = ['payments' => [$payment]];

        [$status, $mpOrder] = $this->call('POST', '/v1/orders', $websiteId, $body, $key);
        // a declined card comes back as 4xx with the order (status failed) inside errors' data
        $mpOrder = isset($mpOrder['id']) ? $mpOrder : ($mpOrder['data'] ?? $mpOrder);
        if (!isset($mpOrder['id'])) {
            Log::error("Mercado Pago: cobrança do pedido {$order['increment_id']} recusada (HTTP {$status}): " . json_encode($mpOrder, JSON_UNESCAPED_UNICODE));
            $error = $mpOrder['errors'][0]['message'] ?? $mpOrder['message'] ?? "HTTP {$status}";
            throw new SalesException('PAYMENT_FAILED', 'O Mercado Pago não aceitou a cobrança: ' . $error . '.');
        }
        $this->save($orderId, $method, $mpOrder);
        if (!empty($digits)) {
            Connection::get()->adapter()->update('mercadopago_payment', $digits, ['external_id = ?' => (string) $mpOrder['id']]);
        }

        return $this->get((string) $mpOrder['id']);
    }

    /**
     * Reads a charge (a Mercado Pago order) back and applies its status to the order (webhook, cron).
     *
     * @return bool false when the payment isn't one of this store's
     */
    public function sync(string $externalId): bool
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow($db->select()->from(['p' => 'mercadopago_payment'], ['order_id', 'method'])
            ->join(['o' => 'sales_order'], 'o.order_id = p.order_id', ['website_id'])->where('p.external_id = ?', $externalId));
        if ($row === false) {
            return false;
        }
        [$status, $mpOrder] = $this->call('GET', '/v1/orders/' . rawurlencode($externalId), (int) $row['website_id']);
        if ($status >= 400 || !isset($mpOrder['id'])) {
            throw new SalesException('PAYMENT_UNAVAILABLE', "Mercado Pago: pagamento #{$externalId} não pôde ser lido (HTTP {$status}).");
        }
        $this->save((int) $row['order_id'], $row['method'], $mpOrder);

        return true;
    }

    /**
     * bin/mercadopago-sync.php: reads back the payments still open from the last 31 days, for a lost
     * notification (or a store without a public address, where there is none).
     *
     * @return string[] the external ids read
     */
    public function syncOpen(): array
    {
        $db = Connection::get()->adapter();
        $ids = array_map('strval', $db->fetchCol($db->select()->from('mercadopago_payment', ['external_id'])
            ->where('status IN (?)', self::OPEN)->where("created_at > NOW() - INTERVAL '31 days'")->order('payment_id')));
        foreach ($ids as $id) {
            try {
                $this->sync($id);
            } catch (SalesException $e) {
                Log::error($e->getMessage()); // the next run tries again
            }
        }

        return $ids;
    }

    /** @throws SalesException PAYMENT_NOT_FOUND */
    public function get(string $externalId): array
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow($db->select()->from('mercadopago_payment')->where('external_id = ?', $externalId))
            ?: throw new SalesException('PAYMENT_NOT_FOUND', 'Pagamento não encontrado.');

        return ['status_label' => self::STATUS_LABELS[$row['status']] ?? $row['status'], 'amount' => (float) $row['amount'], 'card_number' => self::maskCard($row)] + $row;
    }

    /** "4509 95•• •••• 3704"; null without the digits (Pix, a charge from before them) */
    public static function maskCard(array $row): ?string
    {
        if (($row['card_last_four'] ?? null) === null) {
            return null;
        }
        $first = (string) ($row['card_first_six'] ?? '');

        return ($first !== '' ? substr($first, 0, 4) . ' ' . substr($first, 4) . '••' : '•••• ••••') . ' •••• ' . $row['card_last_four'];
    }

    /** @return array[] the order's charges, the newest first */
    public function forOrder(int $orderId): array
    {
        $db = Connection::get()->adapter();
        $ids = $db->fetchCol($db->select()->from('mercadopago_payment', ['external_id'])->where('order_id = ?', $orderId)->order('payment_id DESC'));

        return array_map(fn ($id) => $this->get((string) $id), $ids);
    }

    /**
     * x-signature of a notification (Webhooks → assinatura secreta): "ts=...,v1=..." where v1 is the
     * HMAC-SHA256 of "id:<data.id>;request-id:<x-request-id>;ts:<ts>;" (a missing part is left out).
     */
    public static function validSignature(string $header, string $requestId, string $dataId, string $secret): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            $parts[$name] = $value;
        }
        if (($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') {
            return false;
        }
        $manifest = ($dataId !== '' ? 'id:' . strtolower($dataId) . ';' : '') . ($requestId !== '' ? "request-id:{$requestId};" : '') . "ts:{$parts['ts']};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
    }

    public static function webhookSecret(int $websiteId): string
    {
        return Crypt::decrypt(Config::get('payment/mercadopago/webhook_secret', $websiteId));
    }

    private static function token(int $websiteId): string
    {
        return trim(Crypt::decrypt(Config::get('payment/mercadopago/access_token', $websiteId)));
    }

    /**
     * Stores the charge (a Mercado Pago order with one payment) as the API gave it and, when its status
     * changed, tells the order: approved confirms it; anything else becomes a history note for the admin
     * (never e-mailed). A field the API leaves out of a later read (the Pix QR code) keeps its value.
     */
    private function save(int $orderId, string $method, array $mpOrder): void
    {
        $id = (string) $mpOrder['id'];
        $payment = $mpOrder['transactions']['payments'][0] ?? [];
        $pm = $payment['payment_method'] ?? [];
        $row = array_filter([
            'qr_code' => $pm['qr_code'] ?? null,
            'qr_code_base64' => ($pm['qr_code_base64'] ?? '') ?: null,
            'ticket_url' => $pm['ticket_url'] ?? null,
            'challenge_url' => $pm['transaction_security']['url'] ?? null,
            'expires_at' => isset($payment['date_of_expiration']) ? date('Y-m-d H:i:s', strtotime((string) $payment['date_of_expiration'])) : null,
        ], static fn ($v) => $v !== null) + [
            'status' => self::ORDER_STATUS[(string) $mpOrder['status']] ?? (string) $mpOrder['status'],
            'status_detail' => ($payment['status_detail'] ?? $mpOrder['status_detail'] ?? null) ?: null,
            'amount' => (float) ($payment['amount'] ?? $mpOrder['total_amount'] ?? 0),
            'installments' => $pm['installments'] ?? null,
            'card_brand' => $method === 'mercadopago_pix' ? null : ($pm['id'] ?? null),
        ];
        $previous = Connection::get()->adapter()->transaction(static function ($db) use ($orderId, $method, $id, $row): ?string {
            $previous = $db->fetchOne($db->select()->from('mercadopago_payment', ['status'])->where('external_id = ?', $id)->forUpdate());
            if ($previous === false) {
                $db->insert('mercadopago_payment', ['order_id' => $orderId, 'method' => $method, 'external_id' => $id] + $row);
                return null;
            }
            $db->update('mercadopago_payment', $row, ['external_id = ?' => $id]);

            return (string) $previous;
        });
        if ($previous !== $row['status']) {
            $this->apply($orderId, $id, $row, $previous);
        }
    }

    private function apply(int $orderId, string $externalId, array $row, ?string $previous): void
    {
        $orders = new OrderService();
        $label = self::STATUS_LABELS[$row['status']] ?? $row['status'];
        $what = "Mercado Pago #{$externalId}: {$label}" . ($row['status_detail'] ? " ({$row['status_detail']})" : '')
            . ($row['installments'] > 1 ? ", {$row['installments']}x" : '') . '.';
        if ($row['status'] === 'approved') {
            try {
                $orders->confirmPayment($orderId, $what, null);
                return;
            } catch (SalesException) {
                // canceled (by the cron or the admin) before the money arrived
                $what .= ' ATENÇÃO: o pedido não aguardava mais pagamento. Estorne pelo painel do Mercado Pago ou reabra a venda.';
                Log::alert("Mercado Pago: pagamento #{$externalId} aprovado para o pedido {$orderId}, que não aguardava pagamento");
            }
        } elseif ($previous === null && $row['status'] === 'pending') {
            return; // a new Pix or a card under analysis: nothing to tell yet
        } elseif ($previous === 'approved') {
            $what .= ' Confira o pedido: o pagamento aprovado mudou.';
        }
        $orders->addComment($orderId, $what, false, null);
    }

    /** @return array{0: int, 1: array} HTTP status and the decoded body */
    private function call(string $method, string $path, int $websiteId, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $token = self::token($websiteId);
        if ($token === '') {
            throw new SalesException('PAYMENT_UNAVAILABLE', 'O Mercado Pago não está configurado nesta loja.');
        }
        if ($this->http !== null) {
            return ($this->http)($method, $path, $body);
        }
        $ch = curl_init(self::API . $path);
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        $idempotencyKey === null || $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]
            + ($body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::error("Mercado Pago: {$method} {$path} falhou: " . curl_error($ch));
            throw new SalesException('PAYMENT_UNAVAILABLE', 'Não foi possível falar com o Mercado Pago. Tente de novo em instantes.');
        }

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) $response, true) ?: []];
    }
}
