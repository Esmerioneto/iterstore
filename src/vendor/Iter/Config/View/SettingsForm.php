<?php

declare(strict_types=1);

namespace Iter\Config\View;

use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;
use Iter\Store\Model\Website;

class SettingsForm implements ViewComponentInterface
{
    public function getTemplate(): string
    {
        return 'config/settings-form';
    }

    public function getData(): array
    {
        $old = $_SESSION['iter_old_input'] ?? [];
        unset($_SESSION['iter_old_input']);

        $websites = array_column((new Website())->getList(), 'name', 'website_id');
        $websiteId = (int) ($_GET['website'] ?? 0);
        $websiteId = isset($websites[$websiteId]) ? $websiteId : 0;

        // a website only shows the fields that can change per website
        $fieldsByGroup = [];
        foreach (Config::fields() as $path => $field) {
            if ($websiteId && ($field['scope'] ?? '') !== 'website') {
                continue;
            }
            $own = Config::websiteValue($path, $websiteId);
            $field['uses_default'] = $websiteId && $own === null;
            $field['value'] = ($field['type'] ?? 'text') === 'password' ? '' : ($old[$path] ?? Config::get($path, $websiteId ?: null));
            $field['has_secret'] = ($field['type'] ?? 'text') === 'password' && (string) ($websiteId ? $own : Config::get($path)) !== '';
            $fieldsByGroup[$field['group'] ?? 'Geral'][] = $field;
        }

        // etc/config_groups.json: section, order, icon, description and status chips of each group; a group without it goes last, in "Outros"
        $meta = array_column(\getModuleJson('etc' . DIRECTORY_SEPARATOR . 'config_groups.json'), null, 'group');
        $groups = [];
        foreach ($fieldsByGroup as $label => $fields) {
            $blocks = [];
            foreach ($fields as $field) { // first appearance order; '' = the fields outside any block
                $blocks[$field['block'] ?? ''][] = $field;
            }
            $groups[] = [
                'label' => $label,
                'id' => 'config-group-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label)), '-'),
                'section' => $meta[$label]['section'] ?? 'Outros',
                'position' => (int) ($meta[$label]['position'] ?? 1000),
                'icon' => $meta[$label]['icon'] ?? '⚙️',
                'description' => $meta[$label]['description'] ?? '',
                'chips' => $this->chips($meta[$label]['chips'] ?? [], $websiteId),
                'count' => count($fields),
                'blocks' => $blocks,
            ];
        }
        usort($groups, static fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return ['groups' => $groups, 'websites' => $websites, 'website_id' => $websiteId];
    }

    /**
     * A chip per entry: "path" = one switch ("Log: ligado"), "paths" = how many are on ("Formas ligadas: 2 de 3").
     * Paths of modules that are off are left out.
     *
     * @return list<array{text: string, on: bool}>
     */
    private function chips(array $entries, int $websiteId): array
    {
        $isOn = static fn (string $path): bool => (string) Config::get($path, $websiteId ?: null) === '1';
        $chips = [];
        foreach ($entries as $entry) {
            $paths = array_values(array_filter((array) ($entry['paths'] ?? $entry['path'] ?? []), static fn ($p) => isset(Config::fields()[$p])));
            if (!$paths) {
                continue;
            }
            $on = count(array_filter($paths, $isOn));
            $chips[] = isset($entry['path'])
                ? ['text' => $entry['label'] . ': ' . ($on ? 'ligado' : 'desligado'), 'on' => $on > 0]
                : ['text' => $entry['label'] . ': ' . $on . ' de ' . count($paths), 'on' => $on > 0];
        }

        return $chips;
    }
}
