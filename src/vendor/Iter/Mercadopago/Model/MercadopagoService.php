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

    /** status_detail (without "cc_rejected_") => what it means: the order screen and the reconciliation report */
    public const REASONS = [
        'accredited' => 'Pagamento aprovado', 'insufficient_amount' => 'Saldo ou limite insuficiente', 'bad_filled_card_data' => 'Dados do cartão errados',
        'bad_filled_card_number' => 'Número do cartão errado', 'bad_filled_date' => 'Validade errada', 'bad_filled_security_code' => 'Código de segurança errado',
        'bad_filled_other' => 'Dados do cartão errados', 'rejected_by_issuer' => 'Recusado pelo banco', 'other_reason' => 'Recusado pelo banco',
        'call_for_authorize' => 'O cliente precisa autorizar com o banco', 'required_call_for_authorize' => 'O cliente precisa autorizar com o banco',
        'processing_error' => 'Erro no processamento', 'high_risk' => 'Recusado por risco de fraude', 'duplicated_payment' => 'Pagamento duplicado',
        'card_disabled' => 'Cartão desativado', 'max_attempts' => 'Tentativas demais', 'blacklist' => 'Cartão bloqueado',
        'canceled_transaction' => 'Cancelado', 'expired' => 'Expirou sem pagamento', 'by_collector' => 'Cancelado pela loja', 'refunded' => 'Estornado',
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
     * @param string $deviceId the SDK's device id (X-meli-session-id), for Mercado Pago's fraud check
     * @return array the payment (get())
     * @throws SalesException PAYMENT_METHOD_INVALID, ORDER_STATUS_INVALID, CARD_REQUIRED, INSTALLMENTS_INVALID, PAYMENT_FAILED, PAYMENT_UNAVAILABLE
     */
    public function pay(int $orderId, ?array $card = null, string $deviceId = ''): array
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
        $category = (string) Config::get('payment/mercadopago/item_category', $websiteId) ?: null;
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
            ] + self::payerContact($order['billing_address'] ?? $order['shipping_address'] ?? null),
            // what was bought and the payer's phone and address raise the approval rate (Mercado Pago's quality checklist)
            'items' => array_map(static fn (array $i) => array_filter([
                'external_code' => (string) $i['sku'],
                'title' => mb_substr((string) $i['name'], 0, 255),
                'description' => $i['options'] ? mb_substr((string) $i['options'], 0, 255) : null,
                'quantity' => max(1, (int) round((float) $i['quantity'])),
                'unit_price' => number_format((float) $i['price'], 2, '.', ''),
                'category_id' => $category,
            ], static fn ($v) => $v !== null), $order['items']),
        ];
        // the Orders API refuses items that don't add up to total_amount (order_items_total_amount_mismatch): shipping goes
        // as an item; a discount (or rounding) that still leaves a gap sends the whole order as one item
        if ((float) $order['shipping_amount'] > 0) {
            $body['items'][] = array_filter(['external_code' => 'frete', 'title' => 'Frete', 'quantity' => 1,
                'unit_price' => number_format((float) $order['shipping_amount'], 2, '.', ''), 'category_id' => $category]);
        }
        $cents = array_sum(array_map(static fn (array $i) => $i['quantity'] * (int) round((float) $i['unit_price'] * 100), $body['items']));
        if ($cents !== (int) round((float) $amount * 100)) {
            $body['items'] = [array_filter(['external_code' => $order['increment_id'], 'title' => "Pedido {$order['increment_id']}",
                'quantity' => 1, 'unit_price' => $amount, 'category_id' => $category])];
        }
        $history = $this->payerHistory($order);
        if ($history) {
            $body['additional_info'] = $history;
        }
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
            // "Cartão de débito" takes debit cards (debvisa, debmaster, debelo...) and prepaid ones, which the SDK reports
            // by the plain brand (elo, visa...) and Mercado Pago refuses with installments
            $type = $method === 'mercadopago_debit' ? (str_starts_with($brand, 'deb') ? 'debit_card' : 'prepaid_card') : 'credit_card';
            $payment = ['amount' => $amount, 'payment_method' => array_filter([
                'id' => $brand,
                'type' => $type,
                'token' => $token,
                'installments' => $type === 'prepaid_card' ? null : $installments,
                // the name on the buyer's card bill; a name they recognize avoids chargebacks
                'statement_descriptor' => mb_substr(trim((string) Config::get('payment/mercadopago/statement_descriptor', $websiteId)), 0, 13) ?: null,
            ])];
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

        [$status, $mpOrder] = $this->call('POST', '/v1/orders', $websiteId, $body, $key, $deviceId);
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

    /**
     * Before the order is canceled (CANCEL-PLAN): a charge still open is canceled at Mercado Pago, so it can't be
     * paid later; Mercado Pago down, the order is canceled anyway and the history says to cancel it in the panel.
     * An approved one is refunded in full when $refund (else the admin said how the money went back).
     *
     * @return array{lines: string[], refunded: float} history lines; the amount refunded here
     * @throws SalesException PAYMENT_APPROVED (an open charge was just paid: the order got confirmed, not canceled),
     *                        REFUND_FAILED (nothing canceled)
     */
    public function beforeCancel(array $order, bool $refund): array
    {
        $websiteId = (int) $order['website_id'];
        $lines = [];
        $refunded = 0.0;
        foreach ($this->forOrder((int) $order['order_id']) as $charge) {
            $id = (string) $charge['external_id'];
            if (in_array($charge['status'], self::OPEN, true)) {
                try {
                    [$status, $mpOrder] = $this->call('POST', '/v1/orders/' . rawurlencode($id) . '/cancel', $websiteId, null, 'cancel-' . $id);
                    if ($status < 400 && isset($mpOrder['id'])) {
                        $this->save((int) $order['order_id'], $charge['method'], $mpOrder, false);
                        $lines[] = "Cobrança #{$id} cancelada no Mercado Pago.";
                        continue;
                    }
                    // refused: it may have just been paid; the read confirms the order if so
                    $this->sync($id);
                } catch (SalesException $e) {
                    Log::alert("Mercado Pago: cobrança #{$id} do pedido {$order['increment_id']} não cancelada: " . $e->getMessage());
                }
                if ((new OrderService())->get((int) $order['order_id'])['status'] !== $order['status']) {
                    throw new SalesException('PAYMENT_APPROVED', 'O pagamento acabou de ser aprovado no Mercado Pago; o pedido não foi cancelado.');
                }
                if (in_array($this->get($id)['status'], self::OPEN, true)) { // else it had already expired or been canceled there
                    $lines[] = "ATENÇÃO: cancele a cobrança #{$id} no painel do Mercado Pago (não foi possível cancelar por aqui).";
                }
            } elseif ($charge['status'] === 'approved' && $refund) {
                try {
                    [$status, $mpOrder] = $this->call('POST', '/v1/orders/' . rawurlencode($id) . '/refund', $websiteId, null, 'refund-' . $id);
                } catch (SalesException $e) {
                    [$status, $mpOrder] = [0, ['message' => $e->getMessage()]];
                }
                if ($status === 0 || $status >= 400) {
                    Log::error("Mercado Pago: estorno da cobrança #{$id} do pedido {$order['increment_id']} recusado (HTTP {$status}): " . json_encode($mpOrder, JSON_UNESCAPED_UNICODE));
                    $error = $mpOrder['errors'][0]['message'] ?? $mpOrder['message'] ?? "HTTP {$status}";
                    throw new SalesException('REFUND_FAILED', "O Mercado Pago não fez o estorno ({$error}). O pedido não foi cancelado.");
                }
                // the refund may still be in progress at Mercado Pago: here it's done (the cron only reads open charges)
                Connection::get()->adapter()->update('mercadopago_payment', ['status' => 'refunded'], ['external_id = ?' => $id]);
                $refunded += (float) $charge['amount'];
                $lines[] = sprintf('Estornado R$ %s no Mercado Pago (cobrança #%s).', number_format((float) $charge['amount'], 2, ',', '.'), $id);
            }
        }

        return ['lines' => $lines, 'refunded' => $refunded];
    }

    /** the order's approved charges, what "Estornar R$ X no Mercado Pago" gives back */
    public function approvedAmount(int $orderId): float
    {
        return array_sum(array_column(array_filter($this->forOrder($orderId), static fn (array $c) => $c['status'] === 'approved'), 'amount'));
    }

    /** @throws SalesException PAYMENT_NOT_FOUND */
    public function get(string $externalId): array
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow($db->select()->from('mercadopago_payment')->where('external_id = ?', $externalId))
            ?: throw new SalesException('PAYMENT_NOT_FOUND', 'Pagamento não encontrado.');

        return ['status_label' => self::STATUS_LABELS[$row['status']] ?? $row['status'], 'amount' => (float) $row['amount'], 'card_number' => self::maskCard($row)] + $row;
    }

    /**
     * additional_info.payer for Mercado Pago's fraud check: when the customer signed up, whether this is their first
     * order here and when the one before was. A guest has no account: nothing is sent.
     */
    private function payerHistory(array $order): array
    {
        if (empty($order['customer_id'])) {
            return [];
        }
        $db = Connection::get()->adapter();
        $created = $db->fetchOne($db->select()->from('customer_entity', ['created_at'])->where('customer_id = ?', (int) $order['customer_id']));
        $last = $db->fetchOne($db->select()->from('sales_order', ['MAX(created_at)'])->where('customer_id = ?', (int) $order['customer_id'])
            ->where('order_id <> ?', (int) $order['order_id'])->where("status <> 'canceled'"));
        $date = static fn ($v) => $v ? (new \DateTimeImmutable((string) $v))->format(DATE_RFC3339_EXTENDED) : null;

        return array_filter([
            'payer.registration_date' => $date($created),
            'payer.is_first_purchase_online' => !$last,
            'payer.last_purchase' => $date($last),
        ], static fn ($v) => $v !== null);
    }

    /** payer.phone and payer.address of an order's address (Brazilian phone: DDD + number; a 55 in front is dropped) */
    public static function payerContact(?array $address): array
    {
        if ($address === null) {
            return [];
        }
        $phone = preg_replace('/\D/', '', (string) ($address['telephone'] ?? ''));
        $phone = strlen($phone) > 11 && str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
        $street = array_filter([
            'zip_code' => preg_replace('/\D/', '', (string) ($address['postcode'] ?? '')),
            'street_name' => (string) ($address['street'] ?? ''),
            'street_number' => (string) ($address['number'] ?? ''),
            'neighborhood' => (string) ($address['district'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => (string) ($address['region'] ?? ''),
            'complement' => (string) ($address['complement'] ?? ''),
        ], 'strlen');

        return array_filter([
            'phone' => strlen($phone) >= 10 ? ['area_code' => substr($phone, 0, 2), 'number' => substr($phone, 2)] : null,
            'address' => $street ?: null,
        ]);
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
     * (never e-mailed); !$tell when the caller writes the history itself (beforeCancel). A field the API
     * leaves out of a later read (the Pix QR code) keeps its value.
     */
    private function save(int $orderId, string $method, array $mpOrder, bool $tell = true): void
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
        if ($tell && $previous !== $row['status']) {
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
    private function call(string $method, string $path, int $websiteId, ?array $body = null, ?string $idempotencyKey = null, string $deviceId = ''): array
    {
        $token = self::token($websiteId);
        if ($token === '') {
            throw new SalesException('PAYMENT_UNAVAILABLE', 'O Mercado Pago não está configurado nesta loja.');
        }
        if ($this->http !== null) {
            return ($this->http)($method, $path, $body, $deviceId);
        }
        $ch = curl_init(self::API . $path);
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        $idempotencyKey === null || $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        preg_match('/^[\w\-:.]{1,128}$/', $deviceId) && $headers[] = 'X-meli-session-id: ' . $deviceId;
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
