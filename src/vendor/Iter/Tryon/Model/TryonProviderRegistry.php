<?php

declare(strict_types=1);

namespace Iter\Tryon\Model;

use Iter\Core\Config;
use Iter\Tryon\Api\TryonProviderInterface;

/** Finds the provider in the enabled modules (etc/tryon_provider.json), as Iter_Shipping does with its carriers. */
class TryonProviderRegistry
{
    /** @return TryonProviderInterface[] code => provider */
    public function all(): array
    {
        $providers = [];
        foreach (\getModules() as $entries) {
            foreach ($entries as $key => $enabled) {
                if ((int) $enabled !== 1) {
                    continue;
                }
                $dir = ucfirst(\getModuleVendor($key)) . DIRECTORY_SEPARATOR . ucfirst(\getModuleName($key)) . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'tryon_provider.json';
                foreach ([\getVendorPath(), \getCodePath()] as $base) {
                    if (is_file($base . $dir)) {
                        $class = json_decode((string) file_get_contents($base . $dir), true)['class'] ?? null;
                        $provider = is_string($class) && class_exists($class) ? new $class() : null;
                        if ($provider instanceof TryonProviderInterface) {
                            $providers[$provider->code()] = $provider;
                        }
                        break;
                    }
                }
            }
        }

        return $providers;
    }

    /**
     * The one chosen in Configurações (catalog/tryon/provider), and only it: the consent text names the partner that
     * gets the photo. None chosen: the first configured one.
     */
    public function active(): ?TryonProviderInterface
    {
        $all = $this->all();
        $chosen = (string) Config::get('catalog/tryon/provider');
        if ($chosen !== '') {
            return isset($all[$chosen]) && $all[$chosen]->isConfigured() ? $all[$chosen] : null;
        }
        foreach ($all as $provider) {
            if ($provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }
}
