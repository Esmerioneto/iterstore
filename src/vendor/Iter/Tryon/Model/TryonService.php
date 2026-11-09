<?php

declare(strict_types=1);

namespace Iter\Tryon\Model;

use Iter\Catalog\Model\Product;
use Iter\Catalog\Model\VariantService;
use Iter\Core\Config;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Core\Log;
use Iter\Customer\Model\Customer;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;
use Iter\Tryon\Api\TryonProviderException;
use Iter\Tryon\Api\TryonProviderInterface;

/**
 * The provador virtual (TRYON-PLAN): the customer's photo + a product's image go to the provider, the app asks
 * for the result with get(). The photos are never stored, only their SHA-256: the same photo with the same
 * product image within the result's life gives back the stored result, without the provider (§11.1). Only for
 * customers the store allowed ("Pode usar o provador virtual") and products it allowed ("Provador virtual").
 */
final class TryonService
{
    public const ATTRIBUTE = 'tryon_enabled';
    /** "Tipo de peça no provador": tops, bottoms or one-pieces; empty = tops */
    public const CATEGORY_ATTRIBUTE = 'tryon_category';
    public const CATEGORIES = ['tops', 'bottoms', 'one-pieces'];
    /** the app polls every 2 s; the provider is asked at most this often per request */
    public const POLL_SECONDS = 2;
    /** a generation still processing after this is given up (TRYON_TIMEOUT) */
    public const TIMEOUT_SECONDS = 120;
    /** a stored result this close to expiring is generated again, so it doesn't vanish while the customer looks */
    public const REUSE_MARGIN_SECONDS = 600;
    public const MAX_SIDE = 1536;
    public const MIN_SIDE = 256;

    /** our code => the customer's message; the provider's four codes map to the TRYON_* ones */
    public const MESSAGES = [
        'TRYON_DISABLED' => 'O provador não está disponível agora.',
        'TRYON_NOT_ALLOWED' => 'O provador não está liberado para a sua conta.',
        'TRYON_UNAVAILABLE' => 'O provador não está disponível agora.',
        'TRYON_DAILY_LIMIT' => 'Você já usou o provador %d vezes hoje. Volte amanhã.',
        'TRYON_IN_PROGRESS' => 'Espere a prova atual terminar.',
        'TRYON_BUSY' => 'Muita gente no provador agora. Tente em instantes.',
        'TRYON_FAILED' => 'Não deu certo desta vez. Tente de novo.',
        'TRYON_REFUSED' => 'Não foi possível usar esta foto. Tente outra.',
        'TRYON_TIMEOUT' => 'Demorou demais. Tente de novo.',
        'TRYON_NOT_FOUND' => 'Prova não encontrada.',
        'PRODUCT_NOT_FOUND' => 'Este produto não pode ser experimentado.',
        'PRODUCT_NOT_TRYON' => 'Este produto não pode ser experimentado.',
        'INVALID_IMAGE' => 'Use uma foto JPG, PNG ou WebP com pelo menos 256 pixels de lado.',
        'FILE_TOO_LARGE' => 'A foto é grande demais: o máximo é %d KB.',
    ];

    /** the admin's situations (§10); an expired success is still 'success' in the table */
    public const STATUS_LABELS = ['processing' => 'Gerando', 'success' => 'Pronta', 'error' => 'Erro'];

    /** the admin grid's sortable columns: key => SQL (whitelist) */
    public const SORTS = ['date' => 't.tryon_id', 'customer' => 'customer_name', 'status' => 't.status', 'seconds' => 'seconds', 'cost' => 't.cost', 'hits' => 't.hits'];

    private ?TryonProviderInterface $provider;
    private bool $resolved;

    /** $provider: a fixed one (tests); null = the active one from the registry */
    public function __construct(?TryonProviderInterface $provider = null)
    {
        $this->provider = $provider;
        $this->resolved = $provider !== null;
    }

    public function provider(): ?TryonProviderInterface
    {
        if (!$this->resolved) {
            $this->provider = (new TryonProviderRegistry())->active();
            $this->resolved = true;
        }

        return $this->provider;
    }

    /** module on, a provider configured and the month's ceiling not reached */
    public function available(): bool
    {
        if (!Config::get('catalog/tryon/active') || $this->provider() === null) {
            return false;
        }
        $budget = (float) Config::get('catalog/tryon/monthly_budget_usd');

        return $budget <= 0 || $this->spentThisMonth() < $budget;
    }

    /** @return array{enabled: bool, remaining_today: int, max_upload_kb: int, tips: string[], terms: string} */
    public function settings(?int $customerId): array
    {
        $daily = (int) Config::get('catalog/tryon/daily_per_customer');

        return [
            'enabled' => $customerId !== null && $this->customerAllowed($customerId) && $this->available(),
            'remaining_today' => $customerId === null ? 0 : ($daily <= 0 ? 999 : max(0, $daily - $this->usedToday($customerId))),
            'max_upload_kb' => (int) Config::get('catalog/tryon/max_upload_kb'),
            'tips' => array_values(array_filter(array_map('trim', explode("\n", (string) Config::get('catalog/tryon/tips'))))),
            'terms' => (string) Config::get('catalog/tryon/terms'),
        ];
    }

    /** the store turned "Pode usar o provador virtual" on in the customer's record */
    public function customerAllowed(int $customerId): bool
    {
        return (bool) ((new ValueService())->loadMany(Customer::ENTITY_TYPE, [$customerId], [self::ATTRIBUTE])[$customerId][self::ATTRIBUTE] ?? false);
    }

    /** the product (or a variant's parent) has "Provador virtual" on; active only */
    public function productAllowed(int $productId): bool
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow('SELECT product_id, parent_id, is_active FROM catalog_product WHERE product_id = :id', ['id' => $productId]);
        if (!$row || !$row['is_active']) {
            return false;
        }
        $owner = (int) ($row['parent_id'] ?: $row['product_id']);

        return (bool) ((new ValueService())->loadMany(Product::ENTITY_TYPE, [$owner], [self::ATTRIBUTE])[$owner][self::ATTRIBUTE] ?? false);
    }

    /** the product's "Tipo de peça no provador" (a variant's comes from its parent, like "Provador virtual") */
    public function category(int $productId): string
    {
        $owner = (int) (Connection::get()->adapter()->fetchOne('SELECT COALESCE(parent_id, product_id) FROM catalog_product WHERE product_id = :id', ['id' => $productId]) ?: $productId);
        $value = (string) ((new ValueService())->loadMany(Product::ENTITY_TYPE, [$owner], [self::CATEGORY_ATTRIBUTE])[$owner][self::CATEGORY_ATTRIBUTE] ?? '');

        return in_array($value, self::CATEGORIES, true) ? $value : self::CATEGORIES[0];
    }

    /**
     * @param string $personFile the uploaded photo (already checked as an upload)
     * @return array the request as get() gives it; 'cached' => true when an equal one was reused
     * @throws AttributeException with the codes of MESSAGES
     */
    public function start(int $customerId, int $websiteId, string $personFile, int $productId, ?string $imageCode = null): array
    {
        self::cleanImages();
        if (!$this->available()) {
            throw self::error(Config::get('catalog/tryon/active') && $this->provider() ? 'TRYON_UNAVAILABLE' : 'TRYON_DISABLED');
        }
        if (!$this->customerAllowed($customerId)) {
            throw self::error('TRYON_NOT_ALLOWED');
        }
        $maxKb = (int) Config::get('catalog/tryon/max_upload_kb');
        if ($maxKb > 0 && filesize($personFile) > $maxKb * 1024) {
            throw self::error('FILE_TOO_LARGE', $maxKb);
        }
        if (!$this->productAllowed($productId)) {
            throw self::error(Connection::get()->adapter()->fetchOne('SELECT 1 FROM catalog_product WHERE product_id = :id AND is_active = 1', ['id' => $productId]) ? 'PRODUCT_NOT_TRYON' : 'PRODUCT_NOT_FOUND');
        }
        $person = self::normalize((string) file_get_contents($personFile));
        $garmentFile = $this->garmentFile($productId, $imageCode) ?? throw self::error('INVALID_IMAGE');
        $garment = self::normalize((string) file_get_contents($garmentFile));
        $provider = $this->provider();
        $category = $this->category($productId);
        // the category goes in the garment's hash: the same photo as another type of garment is another result
        $key = ['customer' => $customerId, 'person' => hash('sha256', $person), 'garment' => hash('sha256', $category . "\n" . $garment), 'model' => $provider->model()];

        $db = Connection::get()->adapter();
        $id = $db->transaction(function () use ($db, $key, $customerId, $websiteId, $productId, $provider): int|array {
            // one at a time: the limits are counted and the row inserted under the same lock
            // ponytail: a single store-wide lock; per-customer locks if many generations a second ever matter
            $db->fetchOne("SELECT pg_advisory_xact_lock(hashtext('iter_tryon'))");
            if ($same = $this->same($key)) {
                $same['status'] === 'success' && $db->update('tryon_request', ['hits' => new Expr('hits + 1')], ['tryon_id = ?' => $same['tryon_id']]);

                return ['reused' => (int) $same['tryon_id']];
            }
            $this->checkLimits($customerId);

            return (int) $db->insert('tryon_request', [
                'customer_id' => $customerId, 'website_id' => $websiteId, 'product_id' => $productId,
                'provider' => $provider->code(), 'model' => $key['model'], 'status' => 'processing',
                'person_hash' => $key['person'], 'garment_hash' => $key['garment'],
            ])['tryon_id'];
        });
        if (is_array($id)) {
            return ['cached' => true] + $this->get($id['reused'], $customerId);
        }

        try {
            $sent = $provider->submit(['person' => $person, 'garments' => [$garment], 'category' => $category, 'resultSeconds' => self::resultSeconds()]);
        } catch (TryonProviderException $e) {
            $this->fail($id, $e->errorCode, $e->providerCode, $e->response, $e->getMessage());
            throw self::error(self::ourCode($e->errorCode));
        }
        $db->update('tryon_request', ['provider_task' => $sent['task'], 'request_json' => json_encode($sent['request'], JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')], ['tryon_id = ?' => $id]);
        if (isset($sent['result'])) { // a synchronous provider: the image came with the answer
            $this->apply($id, (string) $db->fetchOne('SELECT created_at FROM tryon_request WHERE tryon_id = ?', [$id]), $sent['result']);
        }

        return $this->get($id, $customerId);
    }

    /**
     * The customer's request, asking the provider when it's still processing (at most every POLL_SECONDS).
     *
     * @return array the row + 'state' (PROCESSING, SUCCESS, ERROR, EXPIRED), 'cached' => false
     * @throws AttributeException TRYON_NOT_FOUND (another customer's included)
     */
    public function get(int $id, int $customerId): array
    {
        $row = $this->row($id, $customerId) ?? throw self::error('TRYON_NOT_FOUND');
        if ($row['status'] === 'processing') {
            $age = time() - strtotime($row['created_at']);
            if ($age > self::TIMEOUT_SECONDS) {
                $this->fail($id, 'TIMEOUT', null, null, 'sem resposta em ' . self::TIMEOUT_SECONDS . ' s');
            } elseif ($row['provider_task'] !== null && ($row['polled_at'] === null || time() - strtotime($row['polled_at']) >= self::POLL_SECONDS)) {
                $this->poll($row);
            }
            $row = $this->row($id, $customerId);
        }
        $expired = $row['status'] === 'success' && strtotime((string) $row['image_expires_at']) <= time();

        return ['state' => $expired ? 'EXPIRED' : strtoupper($row['status']), 'cached' => false] + $row;
    }

    /** Clientes → Provador virtual: the generations, newest first; q finds the customer (name, e-mail) or the product (name, SKU) */
    public function search(array $filters, int $page, int $perPage, string $sort = 'date', string $dir = 'desc'): array
    {
        $db = Connection::get()->adapter();
        $select = $db->select()->from(['t' => 'tryon_request'], [new Expr('COUNT(*)')])
            ->joinLeft(['c' => 'customer_flat'], 'c.customer_id = t.customer_id', [])
            ->joinLeft(['p' => 'catalog_product'], 'p.product_id = t.product_id', []);
        if (isset(self::STATUS_LABELS[$filters['status'] ?? ''])) {
            $select->where('t.status = ?', $filters['status']);
        }
        if (($filters['provider'] ?? '') !== '') {
            $select->where('t.provider = ?', $filters['provider']);
        }
        if ((int) ($filters['website_id'] ?? 0) > 0) {
            $select->where('t.website_id = ?', (int) $filters['website_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . addcslashes(mb_strtolower($filters['q']), '%_\\') . '%';
            $select->where("(lower(c.email) LIKE ? OR lower(CONCAT_WS(' ', c.firstname, c.lastname)) LIKE ?
                OR lower(p.name) LIKE ? OR lower(p.sku) LIKE ?)", $like); // each ? gets $like; backslash is LIKE's default escape (an ESCAPE '\\' would confuse PDO's placeholder parser)
        }
        foreach (['from' => 't.created_at >= CAST(? AS DATE)', 'to' => 't.created_at < CAST(? AS DATE) + 1'] as $key => $condition) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key] ?? '')) {
                $select->where($condition, $filters[$key]);
            }
        }
        $total = (int) $db->fetchOne($select);
        $select->reset(\Iter\Core\Db\Select::COLUMNS)
            ->columns(['t.tryon_id', 't.customer_id', 't.product_id', 't.provider', 't.model', 't.status', 't.cost', 't.error_code', 't.provider_code', 't.hits',
                't.image_url', 't.image_expires_at', 't.created_at', new Expr("NULLIF(TRIM(CONCAT_WS(' ', c.firstname, c.lastname)), '') AS customer_name"),
                new Expr('c.email'), new Expr('p.name AS product_name'), new Expr('p.sku'), new Expr('COALESCE(p.parent_id, p.product_id) AS edit_id'),
                new Expr("CASE WHEN t.status <> 'processing' THEN EXTRACT(EPOCH FROM t.updated_at - t.created_at) END AS seconds")])
            ->order((self::SORTS[$sort] ?? 't.tryon_id') . ($dir === 'asc' ? ' ASC NULLS FIRST' : ' DESC NULLS LAST'))->order('t.tryon_id DESC')->limitPage($page, $perPage);

        return ['items' => $db->fetchAll($select), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        return Connection::get()->adapter()->fetchRow('SELECT * FROM tryon_request WHERE tryon_id = ?', [$id]) ?: null;
    }

    /** the ready image is still on the server */
    public static function imageOpen(array $row): bool
    {
        return $row['status'] === 'success' && $row['image_url'] && strtotime((string) $row['image_expires_at']) > time();
    }

    /**
     * The numbers on top of the admin grid: the month's spend against the ceiling, today's generations,
     * the error rate of the last 7 days and the last answer when it was "key refused / no balance".
     *
     * @return array{spent: float, budget: float, today: int, today_ok: int, week: int, week_errors: int, provider: ?string, refused: ?array}
     */
    public function summary(): array
    {
        $db = Connection::get()->adapter();
        $counts = $db->fetchRow("SELECT COUNT(*) FILTER (WHERE created_at >= :today) AS today, COUNT(*) FILTER (WHERE created_at >= :today AND status = 'success') AS today_ok,
                COUNT(*) AS week, COUNT(*) FILTER (WHERE status = 'error') AS week_errors
            FROM tryon_request WHERE created_at >= :week", ['today' => date('Y-m-d 00:00:00'), 'week' => date('Y-m-d 00:00:00', strtotime('-6 days'))]);
        $last = $db->fetchRow("SELECT tryon_id, provider, error_code, provider_code, updated_at FROM tryon_request WHERE status <> 'processing' ORDER BY updated_at DESC, tryon_id DESC LIMIT 1");

        return [
            'spent' => $this->spentThisMonth(),
            'budget' => (float) Config::get('catalog/tryon/monthly_budget_usd'),
            'today' => (int) $counts['today'],
            'today_ok' => (int) $counts['today_ok'],
            'week' => (int) $counts['week'],
            'week_errors' => (int) $counts['week_errors'],
            'provider' => $this->provider()?->code(),
            'refused' => $last && $last['error_code'] === 'TRYON_UNAVAILABLE' ? $last : null,
        ];
    }

    /** @return string[] the providers that appear in the table, for the filter */
    public function providersUsed(): array
    {
        return array_column(Connection::get()->adapter()->fetchAll('SELECT DISTINCT provider FROM tryon_request ORDER BY provider'), 'provider');
    }

    /** the customer's message for a stored error code */
    public static function message(?string $code): ?string
    {
        return $code === null ? null : sprintf(self::MESSAGES[$code] ?? self::MESSAGES['TRYON_FAILED'], (int) Config::get('catalog/tryon/daily_per_customer'));
    }

    /**
     * The photo as it goes to the provider: decoded and drawn again on white (no EXIF, nothing that isn't image),
     * longest side up to MAX_SIDE, JPEG 90. Same input, same bytes: the SHA-256 finds a repeated request.
     *
     * @throws AttributeException INVALID_IMAGE
     */
    public static function normalize(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || min($info[0], $info[1]) < self::MIN_SIDE) {
            throw self::error('INVALID_IMAGE');
        }
        $source = @imagecreatefromstring($bytes) ?: throw self::error('INVALID_IMAGE');
        $scale = min(1, self::MAX_SIDE / max($info[0], $info[1]));
        [$w, $h] = [(int) round($info[0] * $scale), (int) round($info[1] * $scale)];
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** the file of the product's image: the given field or the first one with a value; a configurable's comes from its image variant */
    public function garmentFile(int $productId, ?string $code = null): ?string
    {
        $db = Connection::get()->adapter();
        $row = $db->fetchRow('SELECT type, parent_id, image_variant_id FROM catalog_product WHERE product_id = :id', ['id' => $productId]);
        $variants = new VariantService();
        $codes = $variants->imageCodes();
        $values = static fn (int $id): array => (new ValueService())->loadMany(Product::ENTITY_TYPE, [$id], $codes)[$id] ?? [];
        $images = match (true) {
            !$row => [],
            $row['type'] === 'configurable' => $variants->imageValues([$productId])[$productId] ?? [],
            default => $values($productId) ?: ($row['parent_id'] ? $variants->imageValues([(int) $row['parent_id']])[(int) $row['parent_id']] ?? [] : []),
        };
        $value = $code !== null ? ($images[$code] ?? null) : (array_values(array_filter(array_map(static fn ($c) => $images[$c] ?? null, $codes)))[0] ?? null);
        $file = $value === null ? null : Config::mediaDir() . ltrim(substr(ValueService::MEDIA_URL, strlen('/media/')), '/') . $value;

        return $file !== null && is_file($file) ? $file : null;
    }

    private static function resultSeconds(): int
    {
        return max(1, (int) Config::get('catalog/tryon/result_hours')) * 3600;
    }

    /** an equal request still good: a result with more than REUSE_MARGIN_SECONDS of life, or one still processing */
    private function same(array $key): ?array
    {
        return Connection::get()->adapter()->fetchRow("SELECT tryon_id, status FROM tryon_request
            WHERE customer_id = :customer AND person_hash = :person AND garment_hash = :garment AND model = :model
              AND ((status = 'success' AND image_expires_at > NOW() + make_interval(secs => :margin))
                OR (status = 'processing' AND created_at > NOW() - make_interval(secs => :timeout)))
            ORDER BY tryon_id DESC LIMIT 1", $key + ['margin' => self::REUSE_MARGIN_SECONDS, 'timeout' => self::TIMEOUT_SECONDS]) ?: null;
    }

    /** @throws AttributeException TRYON_IN_PROGRESS, TRYON_DAILY_LIMIT, TRYON_BUSY, TRYON_UNAVAILABLE */
    private function checkLimits(int $customerId): void
    {
        $db = Connection::get()->adapter();
        $live = ['timeout' => self::TIMEOUT_SECONDS];
        if ($db->fetchOne("SELECT 1 FROM tryon_request WHERE customer_id = :customer AND status = 'processing' AND created_at > NOW() - make_interval(secs => :timeout)", ['customer' => $customerId] + $live)) {
            throw self::error('TRYON_IN_PROGRESS');
        }
        $daily = (int) Config::get('catalog/tryon/daily_per_customer');
        if ($daily > 0 && $this->usedToday($customerId) >= $daily) {
            throw self::error('TRYON_DAILY_LIMIT', $daily);
        }
        if ((int) $db->fetchOne("SELECT COUNT(*) FROM tryon_request WHERE status = 'processing' AND created_at > NOW() - make_interval(secs => :timeout)", $live) >= max(1, (int) Config::get('catalog/tryon/concurrent'))) {
            throw self::error('TRYON_BUSY');
        }
        $budget = (float) Config::get('catalog/tryon/monthly_budget_usd');
        if ($budget > 0 && $this->spentThisMonth() + $this->provider()->estimatedCost(1) > $budget) {
            throw self::error('TRYON_UNAVAILABLE');
        }
    }

    /** today's generations that cost or may cost (errors don't count; reused ones never made a row) */
    private function usedToday(int $customerId): int
    {
        return (int) Connection::get()->adapter()->fetchOne("SELECT COUNT(*) FROM tryon_request WHERE customer_id = :customer AND status IN ('processing','success') AND created_at >= :today",
            ['customer' => $customerId, 'today' => date('Y-m-d 00:00:00')]);
    }

    /** the month's real cost plus an estimate for each one still processing */
    private function spentThisMonth(): float
    {
        $row = Connection::get()->adapter()->fetchRow("SELECT COALESCE(SUM(cost), 0) AS spent, COUNT(*) FILTER (WHERE status = 'processing' AND created_at > NOW() - make_interval(secs => :timeout)) AS open
            FROM tryon_request WHERE created_at >= :month AND status <> 'error'", ['month' => date('Y-m-01 00:00:00'), 'timeout' => self::TIMEOUT_SECONDS]);

        return (float) $row['spent'] + (int) $row['open'] * ($this->provider()?->estimatedCost(1) ?? 0.0);
    }

    private function row(int $id, int $customerId): ?array
    {
        return Connection::get()->adapter()->fetchRow('SELECT * FROM tryon_request WHERE tryon_id = :id AND customer_id = :customer', ['id' => $id, 'customer' => $customerId]) ?: null;
    }

    /** asks the request's own provider; a provider that can't be asked leaves it processing */
    private function poll(array $row): void
    {
        $db = Connection::get()->adapter();
        $now = date('Y-m-d H:i:s');
        $provider = $this->provider();
        if ($provider === null || $provider->code() !== $row['provider']) {
            $provider = (new TryonProviderRegistry())->all()[$row['provider']] ?? null;
        }
        if ($provider === null) {
            $db->update('tryon_request', ['polled_at' => $now], ['tryon_id = ?' => $row['tryon_id']]);

            return;
        }
        try {
            $result = $provider->fetch((string) $row['provider_task']);
        } catch (TryonProviderException $e) {
            Log::error('Provador: não foi possível consultar ' . $row['provider'] . ': ' . $e->getMessage(), ['tryon_id' => $row['tryon_id'], 'provider_code' => $e->providerCode]);
            $db->update('tryon_request', ['polled_at' => $now], ['tryon_id = ?' => $row['tryon_id']]);

            return;
        }
        $this->apply((int) $row['tryon_id'], $row['created_at'], $result);
    }

    /** a provider's result (fetch() or a synchronous submit()) written to the request */
    private function apply(int $id, string $createdAt, array $result): void
    {
        $db = Connection::get()->adapter();
        $now = date('Y-m-d H:i:s');
        if ($result['status'] === 'success' && !isset($result['imageUrl'])) {
            $result['imageUrl'] = self::keepImage((string) ($result['image'] ?? ''));
            if ($result['imageUrl'] === null) {
                $result = ['status' => 'error', 'error' => TryonProviderException::FAILED, 'providerCode' => 'invalid_image'] + $result;
            }
        }
        $data = ['polled_at' => $now, 'updated_at' => $now, 'response_json' => json_encode($result['response'] ?? [], JSON_UNESCAPED_SLASHES)];
        match ($result['status']) {
            'success' => $db->update('tryon_request', $data + ['status' => 'success', 'progress' => 100, 'image_url' => $result['imageUrl'],
                'cost' => $result['cost'] ?? null, 'image_expires_at' => date('Y-m-d H:i:s', strtotime($createdAt) + self::resultSeconds())], ['tryon_id = ?' => $id]),
            'error' => $this->fail($id, $result['error'] ?? TryonProviderException::FAILED, $result['providerCode'] ?? null, $result['response'] ?? [], 'erro do provedor'),
            default => $db->update('tryon_request', $data + ['progress' => $result['progress'] ?? null], ['tryon_id = ?' => $id]),
        };
    }

    /** the folder of the images a synchronous provider returned (public: the app shows them by URL) */
    public static function imageDir(): string
    {
        return Config::mediaDir() . 'tryon' . DIRECTORY_SEPARATOR;
    }

    /**
     * Keeps a returned image under an unguessable name and gives its URL; null when the bytes aren't a JPEG/PNG/WebP.
     * It's the customer's photo: it's deleted when it expires (cleanImages()).
     */
    private static function keepImage(string $bytes): ?string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        is_dir(self::imageDir()) || mkdir(self::imageDir(), 0775, true);
        $name = bin2hex(random_bytes(16)) . image_type_to_extension($info[2]);
        file_put_contents(self::imageDir() . $name, $bytes);

        return Config::mediaUrl('tryon/' . $name);
    }

    /**
     * Deletes the kept images older than their life (Configurações → "Imagem pronta disponível por"), whatever their
     * request became (a deleted customer's included). Runs at each new try-on, so it needs no cron.
     *
     * @return int files deleted
     */
    public static function cleanImages(?int $now = null): int
    {
        $limit = ($now ?? time()) - self::resultSeconds();
        $deleted = 0;
        foreach (glob(self::imageDir() . '*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit && unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** $code: the provider's layer code (UNAVAILABLE...) or TIMEOUT */
    private function fail(int $id, string $code, ?string $providerCode, ?array $response, string $why): void
    {
        $ours = $code === 'TIMEOUT' ? 'TRYON_TIMEOUT' : self::ourCode($code);
        $now = date('Y-m-d H:i:s');
        Connection::get()->adapter()->update('tryon_request', ['status' => 'error', 'error_code' => $ours, 'provider_code' => $providerCode, 'updated_at' => $now]
            + ($response === null ? [] : ['response_json' => json_encode($response, JSON_UNESCAPED_SLASHES)]), ['tryon_id = ?' => $id]);
        // the store has to act on UNAVAILABLE (key refused, no balance); the others are the customer's retry
        $ours === 'TRYON_UNAVAILABLE'
            ? Log::error("Provador: {$ours} na prova {$id} ({$why})", ['provider_code' => $providerCode])
            : Log::info("Provador: {$ours} na prova {$id} ({$why})", ['provider_code' => $providerCode]);
    }

    private static function ourCode(string $layerCode): string
    {
        return match ($layerCode) {
            TryonProviderException::UNAVAILABLE => 'TRYON_UNAVAILABLE',
            TryonProviderException::BUSY => 'TRYON_BUSY',
            TryonProviderException::REFUSED => 'TRYON_REFUSED',
            default => 'TRYON_FAILED',
        };
    }

    private static function error(string $code, int $n = 0): AttributeException
    {
        return new AttributeException($code, sprintf(self::MESSAGES[$code], $n));
    }
}
