<?php

declare(strict_types=1);

namespace Iter\Catalog\Controllers;

use Iter\Admin\Breadcrumb;
use Iter\Catalog\Model\WebHomeService;
use Iter\Core\Messages;
use Iter\Core\View\Layout;
use Iter\Eav\Model\AttributeException;
use Iter\Eav\Model\ValueService;
use Iter\Log\Model\OperationLog;
use Iter\Store\Model\Website;

/** Catálogo → Web: the web store's home (WEB-HOME-PLAN §4) */
class Web
{
    public const ADMIN_RESOURCE = 'Iter_Catalog::web';

    public function Index(): void
    {
        Breadcrumb::set([
            ['label' => 'Dashboard', 'url' => '/admin/index'],
            ['label' => 'Catálogo', 'url' => '/admin/product/index'],
            ['label' => 'Web'],
        ]);

        echo Layout::renderAdmin('admin_web_index');
    }

    /**
     * The whole screen at once. Sections and cards come in the posted order (PHP keeps the keys' order); a stored
     * one posts its id (sections[k][id]), so its photo stays when no new file comes.
     */
    public function Save(): void
    {
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $data = array_intersect_key($_POST, array_flip(['announcement_enabled', 'announcement_text', 'announcement_url', 'campaign_enabled',
            'campaign_title', 'campaign_text', 'campaign_button', 'campaign_url', 'sections_title', 'top_mode', 'carousel_arrows', 'carousel_dots', 'carousel_autoplay']));
        $data['campaign_remove'] = array_map('boolval', (array) ($_POST['campaign_remove'] ?? []));
        $data['sections'] = [];
        try {
            $files = [];
            foreach (array_keys(WebHomeService::CAMPAIGN_FILES) as $field) {
                $files[$field] = self::upload($field, []);
            }
            $data['slides'] = [];
            foreach ((array) ($_POST['slides'] ?? []) as $key => $slide) {
                if (!is_array($slide)) {
                    continue;
                }
                $data['slides'][] = ['id' => (int) ($slide['id'] ?? 0), 'title' => (string) ($slide['title'] ?? ''), 'url' => (string) ($slide['url'] ?? ''),
                    'remove_mobile' => !empty($slide['remove_mobile'])];
                $files['slides'][] = ['image_desktop' => self::upload('slide_desktop', [$key]), 'image_mobile' => self::upload('slide_mobile', [$key])];
            }
            foreach ((array) ($_POST['sections'] ?? []) as $key => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $cards = array_filter((array) ($section['cards'] ?? []), 'is_array');
                $data['sections'][] = [
                    'id' => (int) ($section['id'] ?? 0),
                    'category_id' => (int) ($section['category_id'] ?? 0),
                    'title' => (string) ($section['title'] ?? ''),
                    'remove_image' => !empty($section['remove_image']),
                    'show_banner' => !empty($section['show_banner']),
                    'per_category' => (string) ($section['per_category'] ?? WebHomeService::DEFAULT_PER_CATEGORY),
                    'product_ids' => array_map('intval', array_filter((array) ($section['product_ids'] ?? []), 'is_scalar')),
                    'cards' => array_values(array_map(static fn (array $c) => ['id' => (int) ($c['id'] ?? 0), 'category_id' => (int) ($c['category_id'] ?? 0)], $cards)),
                ];
                $files['sections'][] = [
                    'image' => self::upload('section_image', [$key]),
                    'cards' => array_values(array_map(static fn ($cardKey) => self::upload('card_image', [$key, $cardKey]), array_keys($cards))),
                ];
            }
            $change = (new WebHomeService())->save($websiteId, $data, $files);
            // a photo swapped doesn't change the summary: the count of files sent makes it a change for the log
            $sent = count(array_filter([...array_intersect_key($files, WebHomeService::CAMPAIGN_FILES), ...array_merge([], ...array_map('array_values', $files['slides'] ?? [])),
                ...array_column($files['sections'] ?? [], 'image'),
                ...array_merge([], ...array_column($files['sections'] ?? [], 'cards'))]));
            $sent && $change['after']['Arquivos enviados'] = (string) $sent;
            OperationLog::active() && OperationLog::record('update', 'web_home', $websiteId, 'Home web: ' . ((new Website())->load($websiteId)->getData('name') ?? $websiteId), $change['before'], $change['after']);
            Messages::addSuccess('Home da loja web salva.');
        } catch (AttributeException $e) {
            // ponytail: the screen reloads as saved, so what was typed is lost; the form's own checks (maxlength, required, pattern) stop most errors first
            Messages::addError($e->getMessage());
        }
        header('Location: /admin/web/index?website_id=' . $websiteId);
        exit;
    }

    /**
     * One file of $_FILES[$field] at $path (["k"] for section_image[k], ["k", "c"] for card_image[k][c]);
     * null when none was chosen
     */
    private static function upload(string $field, array $path): ?array
    {
        $at = static function (string $part) use ($field, $path): mixed {
            $value = $_FILES[$field][$part] ?? null;
            foreach ($path as $key) {
                $value = is_array($value) ? ($value[$key] ?? null) : null;
            }

            return $value;
        };
        $error = $at('error');
        if ($error === null || is_array($error) || (int) $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return ValueService::uploaded(['error' => (int) $error, 'name' => (string) $at('name'), 'tmp_name' => (string) $at('tmp_name')]);
    }
}
