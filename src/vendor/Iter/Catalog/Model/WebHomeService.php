<?php

declare(strict_types=1);

namespace Iter\Catalog\Model;

use Iter\Core\Config;
use Iter\Core\Db\Adapter;
use Iter\Core\Db\Connection;
use Iter\Core\Db\Expr;
use Iter\Eav\InputTypeRegistry;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;

/**
 * WEB-HOME-PLAN §3: the web store's home (Catálogo → Web, the webHome query), per website. save() takes the
 * whole screen at once: it checks everything first, copies the new files, writes the rows in one transaction
 * (sections and cards are rewritten in the posted order) and only after the commit deletes the files that left.
 * Texts are plain text (the store escapes them); links only "/…" (not "//") or "https://".
 * WEB-HOME-2-PLAN: the top is the campaign (banner) or a carousel of slides; each section may show its category's
 * banners and per_category products (the chosen ones first, then the newest of the category).
 */
final class WebHomeService
{
    public const WEB_CARD_LIMIT = 4;
    public const SLIDE_LIMIT = 5;
    public const SLIDE_TITLE_MAX = 80;
    public const DEFAULT_PER_CATEGORY = 4;
    public const MAX_PER_CATEGORY = MobileService::MAX_PER_CATEGORY;
    public const AUTOPLAY_MAX = 60;
    public const TOP_MODES = ['banner' => 'Banner (campanha)', 'carousel' => 'Carrossel'];
    public const VIDEO_MAX_BYTES = 20 * 1024 * 1024;
    public const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
    /** field => [label, max characters] */
    public const TEXTS = [
        'announcement_text' => ['Texto do aviso', 120],
        'campaign_title' => ['Título da campanha', 80],
        'campaign_text' => ['Texto da campanha', 300],
        'campaign_button' => ['Texto do botão', 30],
        'sections_title' => ['Título das seções', 80],
    ];
    public const SECTION_TITLE_MAX = 80;
    /** the campaign's files: field => label */
    public const CAMPAIGN_FILES = ['campaign_video' => 'Vídeo da campanha', 'campaign_image_desktop' => 'Imagem da campanha (desktop)', 'campaign_image_mobile' => 'Imagem da campanha (iPhone)'];
    private const FOLDER = 'web';
    private const HOME_DEFAULTS = [
        'announcement_enabled' => 0, 'announcement_text' => null, 'announcement_url' => null, 'campaign_enabled' => 0, 'campaign_video' => null,
        'campaign_image_desktop' => null, 'campaign_image_mobile' => null, 'campaign_title' => null, 'campaign_text' => null, 'campaign_button' => null, 'campaign_url' => null,
        'sections_title' => null, 'top_mode' => 'banner', 'carousel_arrows' => 1, 'carousel_dots' => 1, 'carousel_autoplay' => 5,
    ];
    private const SLIDE_FILES = ['image_desktop', 'image_mobile'];

    /**
     * Everything stored, as the admin screen shows it (inactive categories too, flagged)
     *
     * @return array{home: array, slides: list<array{slide_id: int, image_desktop: string, image_mobile: ?string, title: ?string, url: ?string}>,
     *     sections: list<array{section_id: int, category_id: int, title: string, image: string, active: bool, show_banner: bool, per_category: int,
     *     product_ids: int[], cards: list<array{card_id: int, category_id: int, image: string, active: bool}>}>}
     */
    public function get(int $websiteId): array
    {
        $db = $this->db();
        $home = $db->fetchRow('SELECT * FROM catalog_web_home WHERE website_id = ?', [$websiteId]) ?: [];
        $active = $this->activeCategoryIds($websiteId);
        $sections = $db->fetchAll('SELECT section_id, category_id, title, image, show_banner, per_category FROM catalog_web_section WHERE website_id = ? ORDER BY position, section_id', [$websiteId]);
        $cards = [];
        $cardRows = $sections ? $db->fetchAll($db->select()->from('catalog_web_card', ['card_id', 'section_id', 'category_id', 'image'])
            ->where('section_id IN (?)', array_map('intval', array_column($sections, 'section_id')))->order(['position', 'card_id'])) : [];
        foreach ($cardRows as $card) {
            $cards[(int) $card['section_id']][] = ['card_id' => (int) $card['card_id'], 'category_id' => (int) $card['category_id'], 'image' => (string) $card['image'], 'active' => isset($active[(int) $card['category_id']])];
        }
        $chosen = [];
        foreach ($sections ? $db->fetchAll($db->select()->from('catalog_web_section_product', ['section_id', 'product_id'])
            ->where('section_id IN (?)', array_map('intval', array_column($sections, 'section_id')))->order(['section_id', 'position'])) : [] as $row) {
            $chosen[(int) $row['section_id']][] = (int) $row['product_id'];
        }
        $slides = array_map(static fn (array $s) => ['slide_id' => (int) $s['slide_id'], 'image_desktop' => (string) $s['image_desktop'], 'image_mobile' => $s['image_mobile'] ?: null,
            'title' => $s['title'] ?: null, 'url' => $s['url'] ?: null], $db->fetchAll('SELECT * FROM catalog_web_slide WHERE website_id = ? ORDER BY position, slide_id', [$websiteId]));

        return [
            'home' => array_intersect_key($home, self::HOME_DEFAULTS) + self::HOME_DEFAULTS,
            'slides' => $slides,
            'sections' => array_map(static fn (array $s) => [
                'section_id' => (int) $s['section_id'], 'category_id' => (int) $s['category_id'], 'title' => (string) $s['title'], 'image' => (string) $s['image'],
                'active' => isset($active[(int) $s['category_id']]), 'show_banner' => (bool) $s['show_banner'], 'per_category' => (int) $s['per_category'],
                'product_ids' => $chosen[(int) $s['section_id']] ?? [], 'cards' => $cards[(int) $s['section_id']] ?? [],
            ], $sections),
        ];
    }

    /**
     * What the store shows (webHome): a block that is off is null; sections and cards of an inactive category
     * (or under an inactive one) are left out. Files as absolute URLs. The campaign only with the top as a banner,
     * the slides only with it as a carousel. A section's banners only with its "Mostrar banner" on; its products
     * are the chosen ones (active, of the website, still in the category) and then the newest, up to per_category.
     *
     * @return array{announcement: ?array, campaign: ?array, sections_title: ?string, slides: list<array>, carousel: array{arrows: bool, dots: bool, autoplay: int},
     *     sections: list<array{category_id: int, title: string, image: string, banners: string[], product_ids: int[], cards: list<array{category_id: int, image: string}>}>}
     */
    public function read(int $websiteId): array
    {
        $stored = $this->get($websiteId);
        $home = $stored['home'];
        $url = static fn (?string $file): ?string => $file === null || $file === '' ? null : self::url($file, $websiteId);
        $names = $stored['sections'] ? $this->db()->fetchPairs('SELECT category_id, name FROM catalog_category WHERE website_id = ?', [$websiteId]) : [];
        $sections = array_values(array_filter($stored['sections'], static fn ($s) => $s['active']));
        $banners = (new CategoryBannerService())->lists(array_column(array_filter($sections, static fn ($s) => $s['show_banner']), 'category_id'));
        $base = Config::requestBaseUrl($websiteId);
        $carousel = $home['top_mode'] === 'carousel';

        return [
            'announcement' => $home['announcement_enabled'] && (string) $home['announcement_text'] !== ''
                ? ['text' => (string) $home['announcement_text'], 'url' => $home['announcement_url'] ?: null] : null,
            'campaign' => !$carousel && $home['campaign_enabled'] && $home['campaign_image_desktop'] ? [
                'video' => $url($home['campaign_video']), 'image_desktop' => $url($home['campaign_image_desktop']), 'image_mobile' => $url($home['campaign_image_mobile']),
                'title' => $home['campaign_title'] ?: null, 'text' => $home['campaign_text'] ?: null, 'button' => $home['campaign_button'] ?: null, 'url' => $home['campaign_url'] ?: null,
            ] : null,
            'sections_title' => $home['sections_title'] ?: null,
            'slides' => $carousel ? array_map(static fn (array $s) => ['image_desktop' => $url($s['image_desktop']), 'image_mobile' => $url($s['image_mobile']),
                'title' => $s['title'], 'url' => $s['url']], $stored['slides']) : [],
            'carousel' => ['arrows' => (bool) $home['carousel_arrows'], 'dots' => (bool) $home['carousel_dots'], 'autoplay' => (int) $home['carousel_autoplay']],
            'sections' => array_map(fn (array $s) => [
                'category_id' => $s['category_id'],
                'title' => $s['title'] !== '' ? $s['title'] : (string) ($names[$s['category_id']] ?? ''),
                'image' => $url($s['image']),
                'banners' => array_map(static fn (array $b) => $base . $b['url'], $banners[$s['category_id']] ?? []),
                'product_ids' => $this->sectionProducts($websiteId, $s),
                'cards' => array_values(array_map(static fn (array $c) => ['category_id' => $c['category_id'], 'image' => $url($c['image'])], array_filter($s['cards'], static fn ($c) => $c['active']))),
            ], $sections),
        ];
    }

    /**
     * The whole home of a website.
     *
     * @param array $data announcement_enabled, announcement_text, announcement_url, campaign_enabled, campaign_title,
     *                    campaign_text, campaign_button, campaign_url, sections_title (empty = no heading), campaign_remove (field => true: drop the stored file),
     *                    top_mode (banner|carousel), carousel_arrows, carousel_dots, carousel_autoplay (seconds, 0 = off; each omitted = as saved),
     *                    slides: list of {id?: stored slide_id, title, url, remove_mobile?},
     *                    sections: list of {id?: stored section_id, category_id, title, remove_image?, show_banner, per_category, product_ids: int[] in order,
     *                    cards: list of {id?: stored card_id, category_id}}
     * @param array $files uploads ({path, name}, as ValueService::uploaded()): campaign_video, campaign_image_desktop,
     *                     campaign_image_mobile; slides: index => {image_desktop?, image_mobile?}; sections: index => {image?, cards?: index => upload}
     * @return array{before: array<string, string>, after: array<string, string>} a summary for the operation log
     * @throws AttributeException WEBSITE_NOT_FOUND, TEXT_TOO_LONG, URL_INVALID, CATEGORY_NOT_FOUND, CATEGORY_NOT_CHILD,
     *                            WEB_CARD_LIMIT, WEB_SLIDE_LIMIT, WEB_TOP_INVALID, WEB_AUTOPLAY_INVALID, WEB_PER_CATEGORY_INVALID,
     *                            WEB_PRODUCT_INVALID, VALUE_REQUIRED, IMAGE_REQUIRED, FILE_TYPE_INVALID, FILE_TOO_LARGE
     */
    public function save(int $websiteId, array $data, array $files = []): array
    {
        if (!Config::websiteExists($websiteId)) {
            throw new AttributeException('WEBSITE_NOT_FOUND', 'Website não encontrado.');
        }
        $current = $this->get($websiteId);
        $home = ['announcement_enabled' => empty($data['announcement_enabled']) ? 0 : 1, 'campaign_enabled' => empty($data['campaign_enabled']) ? 0 : 1];
        foreach (self::TEXTS as $field => [$label, $max]) {
            $home[$field] = self::text($data[$field] ?? null, $label, $max);
        }
        $home['announcement_url'] = self::link($data['announcement_url'] ?? null, 'Link do aviso');
        $home['campaign_url'] = self::link($data['campaign_url'] ?? null, 'Link da campanha');
        if ($home['announcement_enabled'] && $home['announcement_text'] === null) {
            throw new AttributeException('VALUE_REQUIRED', 'Aviso ligado: escreva o texto do aviso.');
        }

        $new = []; // stored name => upload path, copied before the commit
        $image = static function (?array $upload, string $label) use (&$new): ?string {
            if ($upload === null) {
                return null;
            }
            $file = ValueService::storedName(self::FOLDER, $upload, InputTypeRegistry::get('image')['accept'], $label);
            $new[$file] = (string) $upload['path'];

            return $file;
        };
        foreach (self::CAMPAIGN_FILES as $field => $label) {
            $upload = $files[$field] ?? null;
            $home[$field] = match (true) {
                $upload !== null && $field === 'campaign_video' => $this->video($upload, $new),
                $upload !== null => $image($upload, $label),
                !empty($data['campaign_remove'][$field]) => null,
                default => $current['home'][$field],
            };
        }
        if ($home['campaign_enabled'] && $home['campaign_image_desktop'] === null) {
            throw new AttributeException('IMAGE_REQUIRED', $home['campaign_video'] === null
                ? 'Campanha ligada: envie a imagem de desktop.'
                : 'Campanha com vídeo: envie a imagem de desktop, que é a capa do vídeo.');
        }
        $home['top_mode'] = (string) ($data['top_mode'] ?? $current['home']['top_mode']);
        isset(self::TOP_MODES[$home['top_mode']]) || throw new AttributeException('WEB_TOP_INVALID', 'Topo da home: escolha Banner ou Carrossel.');
        foreach (['carousel_arrows', 'carousel_dots'] as $field) {
            $home[$field] = isset($data[$field]) ? (int) (bool) $data[$field] : (int) $current['home'][$field];
        }
        $autoplay = $data['carousel_autoplay'] ?? $current['home']['carousel_autoplay'];
        if (!is_numeric($autoplay) || (int) $autoplay != $autoplay || $autoplay < 0 || $autoplay > self::AUTOPLAY_MAX) {
            throw new AttributeException('WEB_AUTOPLAY_INVALID', 'Carrossel: passar sozinho a cada 0 a ' . self::AUTOPLAY_MAX . ' segundos (0 = não passa sozinho).');
        }
        $home['carousel_autoplay'] = (int) $autoplay;

        $storedSlides = array_column($current['slides'], null, 'slide_id');
        $slideData = array_values(array_filter((array) ($data['slides'] ?? []), 'is_array'));
        if (count($slideData) > self::SLIDE_LIMIT) {
            throw new AttributeException('WEB_SLIDE_LIMIT', 'Carrossel: no máximo ' . self::SLIDE_LIMIT . ' slides.');
        }
        $slides = [];
        foreach ($slideData as $i => $slide) {
            $label = 'Slide ' . ($i + 1);
            $stored = $storedSlides[(int) ($slide['id'] ?? 0)] ?? null;
            $slides[] = [
                'image_desktop' => $image($files['slides'][$i]['image_desktop'] ?? null, "{$label}: imagem de desktop")
                    ?? $stored['image_desktop']
                    ?? throw new AttributeException('IMAGE_REQUIRED', "{$label}: envie a imagem de desktop."),
                'image_mobile' => $image($files['slides'][$i]['image_mobile'] ?? null, "{$label}: imagem do iPhone")
                    ?? (empty($slide['remove_mobile']) ? $stored['image_mobile'] ?? null : null),
                'title' => self::text($slide['title'] ?? null, "{$label}: título", self::SLIDE_TITLE_MAX),
                'url' => self::link($slide['url'] ?? null, "{$label}: link"),
            ];
        }
        if ($home['top_mode'] === 'carousel' && !$slides) {
            throw new AttributeException('VALUE_REQUIRED', 'Topo em carrossel: adicione pelo menos um slide.');
        }

        $categories = array_column($this->db()->fetchAll('SELECT category_id, parent_id, name FROM catalog_category WHERE website_id = ?', [$websiteId]), null, 'category_id');
        $storedSections = array_column($current['sections'], 'image', 'section_id');
        $storedCards = array_column(array_merge([], ...array_column($current['sections'], 'cards')), 'image', 'card_id');
        $sectionData = array_values((array) ($data['sections'] ?? []));
        $linked = []; // category => its parent products (variants take their parent's categories)
        $sectionCategories = array_map(static fn ($s) => (int) ($s['category_id'] ?? 0), $sectionData);
        foreach ($sectionCategories ? $this->db()->fetchAll($this->db()->select()->from(['cp' => 'catalog_category_product'], ['category_id', 'product_id'])
            ->join(['p' => 'catalog_product'], 'p.product_id = cp.product_id AND p.parent_id IS NULL', [])->where('cp.category_id IN (?)', $sectionCategories)) : [] as $row) {
            $linked[(int) $row['category_id']][(int) $row['product_id']] = true;
        }
        $sections = [];
        foreach ($sectionData as $i => $section) {
            $categoryId = (int) ($section['category_id'] ?? 0);
            $category = $categories[$categoryId] ?? throw new AttributeException('CATEGORY_NOT_FOUND', 'Seção ' . ($i + 1) . ': escolha uma categoria deste website.');
            $label = 'Seção "' . $category['name'] . '"';
            $cards = array_values((array) ($section['cards'] ?? []));
            if (count($cards) > self::WEB_CARD_LIMIT) {
                throw new AttributeException('WEB_CARD_LIMIT', "{$label}: no máximo " . self::WEB_CARD_LIMIT . ' cards.');
            }
            $perCategory = $section['per_category'] ?? self::DEFAULT_PER_CATEGORY;
            if (!is_numeric($perCategory) || (int) $perCategory != $perCategory || $perCategory < 0 || $perCategory > self::MAX_PER_CATEGORY) {
                throw new AttributeException('WEB_PER_CATEGORY_INVALID', "{$label}: exiba de 0 a " . self::MAX_PER_CATEGORY . ' produtos.');
            }
            $productIds = array_values(array_map('intval', (array) ($section['product_ids'] ?? [])));
            if (count($productIds) > self::MAX_PER_CATEGORY || count($productIds) !== count(array_unique($productIds))
                || array_diff_key(array_flip($productIds), $linked[$categoryId] ?? [])) {
                throw new AttributeException('WEB_PRODUCT_INVALID', "{$label}: escolha até " . self::MAX_PER_CATEGORY . ' produtos, sem repetir, ligados à categoria (variações não).');
            }
            $row = [
                'category_id' => $categoryId,
                'show_banner' => empty($section['show_banner']) ? 0 : 1,
                'per_category' => (int) $perCategory,
                'product_ids' => $productIds,
                'title' => self::text($section['title'] ?? null, "{$label}: título", self::SECTION_TITLE_MAX) ?? '',
                // optional: without it the store uses the section's first product photo
                'image' => $image($files['sections'][$i]['image'] ?? null, "{$label}: foto")
                    ?? (empty($section['remove_image']) ? $storedSections[(int) ($section['id'] ?? 0)] ?? '' : ''),
                'cards' => [],
            ];
            foreach ($cards as $j => $card) {
                $cardCategory = (int) ($card['category_id'] ?? 0);
                $cardName = $categories[$cardCategory]['name'] ?? throw new AttributeException('CATEGORY_NOT_FOUND', "{$label}, card " . ($j + 1) . ': escolha uma categoria deste website.');
                if (!self::isUnder($cardCategory, $categoryId, $categories)) {
                    throw new AttributeException('CATEGORY_NOT_CHILD', "{$label}: o card \"{$cardName}\" precisa ser uma subcategoria de \"{$category['name']}\".");
                }
                $row['cards'][] = [
                    'category_id' => $cardCategory,
                    'image' => $image($files['sections'][$i]['cards'][$j] ?? null, "{$label}, card \"{$cardName}\"")
                        ?? $storedCards[(int) ($card['id'] ?? 0)]
                        ?? throw new AttributeException('IMAGE_REQUIRED', "{$label}: envie a foto do card \"{$cardName}\"."),
                ];
            }
            $sections[] = $row;
        }

        $copied = [];
        try {
            foreach ($new as $file => $upload) {
                $target = Config::mediaDir() . $file;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Não foi possível criar a pasta ' . dirname($target));
                }
                if (!copy($upload, $target)) {
                    throw new \RuntimeException("Não foi possível gravar {$target}");
                }
                $copied[] = $target;
            }
            $this->db()->transaction(static function (Adapter $db) use ($websiteId, $home, $sections, $slides): void {
                $db->insertOnConflict('catalog_web_home', ['website_id' => $websiteId] + $home + ['updated_at' => new Expr('NOW()')], ['website_id']);
                $db->delete('catalog_web_section', ['website_id = ?' => $websiteId]); // the cards and chosen products go by CASCADE
                foreach ($sections as $position => $section) {
                    $id = (int) $db->insert('catalog_web_section', ['website_id' => $websiteId, 'category_id' => $section['category_id'], 'title' => $section['title'], 'image' => $section['image'],
                        'show_banner' => $section['show_banner'], 'per_category' => $section['per_category'], 'position' => $position])['section_id'];
                    $db->insertMultiple('catalog_web_card', array_map(static fn (array $c, int $p) => ['section_id' => $id, 'category_id' => $c['category_id'], 'image' => $c['image'], 'position' => $p], $section['cards'], array_keys($section['cards'])));
                    $db->insertMultiple('catalog_web_section_product', array_map(static fn (int $productId, int $p) => ['section_id' => $id, 'product_id' => $productId, 'position' => $p], $section['product_ids'], array_keys($section['product_ids'])));
                }
                $db->delete('catalog_web_slide', ['website_id = ?' => $websiteId]);
                $db->insertMultiple('catalog_web_slide', array_map(static fn (array $s, int $p) => ['website_id' => $websiteId, 'position' => $p] + $s, $slides, array_keys($slides)));
            });
        } catch (\Throwable $e) {
            array_map('unlink', $copied);
            throw $e;
        }
        self::unlink(array_diff(self::files($current), self::files(['home' => $home, 'slides' => $slides, 'sections' => $sections])));

        return ['before' => self::summary($current['home'], $current['slides'], $current['sections']), 'after' => self::summary($home, $slides, $sections)];
    }

    /** the files of the sections and cards of a category (CategoryService::delete: the rows go by CASCADE, the files don't) */
    public function categoryFiles(int $categoryId): array
    {
        return $this->db()->fetchCol('SELECT image FROM catalog_web_section WHERE category_id = ? UNION SELECT c.image FROM catalog_web_card c
            WHERE c.category_id = ? OR c.section_id IN (SELECT section_id FROM catalog_web_section WHERE category_id = ?)', [$categoryId, $categoryId, $categoryId]);
    }

    /** @param string[] $files names under public/media */
    public static function unlink(array $files): void
    {
        foreach ($files as $file) {
            $file !== '' && @unlink(Config::mediaDir() . $file);
        }
    }

    public static function url(string $file, int $websiteId): string
    {
        return Config::requestBaseUrl($websiteId) . Config::mediaUrl($file);
    }

    /** @return array<int, true> the website's categories that are active with every parent active */
    private function activeCategoryIds(int $websiteId): array
    {
        $rows = array_column($this->db()->fetchAll('SELECT category_id, parent_id, is_active FROM catalog_category WHERE website_id = ?', [$websiteId]), null, 'category_id');
        $active = [];
        foreach ($rows as $id => $row) {
            $ok = true;
            for ($at = $row, $seen = 0; $at !== null && $seen < 100; $at = $at['parent_id'] === null ? null : ($rows[(int) $at['parent_id']] ?? null), $seen++) {
                $ok = $ok && (bool) $at['is_active'];
            }
            $ok && $active[(int) $id] = true;
        }

        return $active;
    }

    /** $child is below $parent (a child, grandchild…) */
    private static function isUnder(int $child, int $parent, array $categories): bool
    {
        for ($at = $categories[$child]['parent_id'] ?? null, $seen = 0; $at !== null && $seen < 100; $at = $categories[(int) $at]['parent_id'] ?? null, $seen++) {
            if ((int) $at === $parent) {
                return true;
            }
        }

        return false;
    }

    /** plain text, trimmed; '' = null */
    private static function text(mixed $value, string $label, int $max): ?string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', (string) $value));
        if (mb_strlen($value) > $max) {
            throw new AttributeException('TEXT_TOO_LONG', "{$label}: no máximo {$max} caracteres.");
        }

        return $value === '' ? null : $value;
    }

    /** "/…" of this site (not "//", which is another site) or https://; '' = null */
    private static function link(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // a backslash is a slash to browsers ("/\evil.example" = "//evil.example"); spaces and control characters break the link
        if (mb_strlen($value) > 500 || !preg_match('#^(/(?![/\\\\])|https://[^/\\\\\s]+)[^\\\\\s\x00-\x1f\x7f]*$#u', $value)) {
            throw new AttributeException('URL_INVALID', "{$label}: use um endereço desta loja começando com / (ex.: /promocoes) ou um link https://.");
        }

        return $value;
    }

    /** @param array<string, string> $new stored name => upload path (the video joins the files to copy) */
    private function video(array $upload, array &$new): string
    {
        $path = (string) $upload['path'];
        $mime = is_file($path) ? mime_content_type($path) : false;
        $ext = self::VIDEO_TYPES[$mime] ?? throw new AttributeException('FILE_TYPE_INVALID', "'" . ($upload['name'] ?? '') . "' não é um vídeo aceito (MP4 ou WEBM).");
        if (filesize($path) > self::VIDEO_MAX_BYTES) {
            throw new AttributeException('FILE_TOO_LARGE', 'O vídeo pode ter até ' . intdiv(self::VIDEO_MAX_BYTES, 1048576) . ' MB.');
        }
        $file = self::FOLDER . '/' . bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $new[$file] = $path;

        return $file;
    }

    /**
     * A section's products: the chosen ones first (in order), then the newest of the category, up to per_category;
     * only parents that are active, in the website and still in the category
     * ponytail: one query per section, as a home has a handful of them
     *
     * @return int[]
     */
    private function sectionProducts(int $websiteId, array $section): array
    {
        if ($section['per_category'] < 1) {
            return [];
        }

        return array_map('intval', $this->db()->fetchCol('SELECT cp.product_id FROM catalog_category_product cp
            JOIN catalog_product p ON p.product_id = cp.product_id AND p.parent_id IS NULL AND p.is_active = 1
            JOIN catalog_product_website pw ON pw.product_id = cp.product_id AND pw.website_id = ?
            LEFT JOIN catalog_web_section_product sp ON sp.section_id = ? AND sp.product_id = cp.product_id
            WHERE cp.category_id = ? ORDER BY sp.position NULLS LAST, cp.product_id DESC LIMIT ' . (int) $section['per_category'],
            [$websiteId, $section['section_id'], $section['category_id']]));
    }

    /** @return string[] every file of a get() result (or of what save() writes) */
    private static function files(array $stored): array
    {
        return array_merge(
            array_filter(array_intersect_key($stored['home'], self::CAMPAIGN_FILES)),
            array_filter(array_merge([], ...array_map(static fn (array $s) => array_values(array_intersect_key($s, array_flip(self::SLIDE_FILES))), $stored['slides']))),
            array_column($stored['sections'], 'image'),
            array_column(array_merge([], ...array_column($stored['sections'], 'cards')), 'image'),
        );
    }

    /** @return array<string, string> label => readable value, for the operation log */
    private static function summary(array $home, array $slides, array $sections): array
    {
        return [
            'Topo' => $home['top_mode'] === 'carousel' ? 'carrossel (' . count($slides) . ' slides' . ($home['carousel_arrows'] ? ', setas' : '') . ($home['carousel_dots'] ? ', indicadores' : '')
                . ($home['carousel_autoplay'] ? ', passa a cada ' . $home['carousel_autoplay'] . ' s' : '') . ')' : 'banner',
            'Aviso' => $home['announcement_enabled'] ? (string) $home['announcement_text'] : 'desligado',
            'Campanha' => $home['campaign_enabled'] ? ((string) $home['campaign_title'] ?: 'ligada') . ($home['campaign_video'] ? ' (com vídeo)' : '') : 'desligada',
            'Título das seções' => (string) $home['sections_title'] ?: 'nenhum',
            'Seções' => (string) count($sections),
            'Cards' => (string) array_sum(array_map(static fn ($s) => count($s['cards']), $sections)),
            'Produtos por seção' => implode(', ', array_map(static fn ($s) => $s['per_category'] . ($s['product_ids'] ? ' (' . count($s['product_ids']) . ' escolhidos)' : '')
                . ($s['show_banner'] ? ' + banner' : ''), $sections)),
        ];
    }

    private function db(): Adapter
    {
        return Connection::get()->adapter();
    }
}
