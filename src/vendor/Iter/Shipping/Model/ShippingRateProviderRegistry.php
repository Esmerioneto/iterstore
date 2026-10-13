<?php

declare(strict_types=1);

namespace Iter\Shipping\Model;

use Iter\Shipping\Api\ShippingRateProviderInterface;

/** Discovers providers from enabled modules; Iter_Shipping does not depend on a concrete carrier. */
class ShippingRateProviderRegistry
{
    /** @return ShippingRateProviderInterface[] */
    public function all(): array
    {
        $providers = [];
        foreach (\getModules() as $entries) {
            foreach ($entries as $key => $enabled) {
                if ((int) $enabled !== 1) {
                    continue;
                }
                $dir = ucfirst(\getModuleVendor($key)) . DIRECTORY_SEPARATOR
                    . ucfirst(\getModuleName($key)) . DIRECTORY_SEPARATOR
                    . 'etc' . DIRECTORY_SEPARATOR . 'shipping_provider.json';
                $metadata = null;
                foreach ([\getVendorPath(), \getCodePath()] as $base) {
                    $path = $base . $dir;
                    if (is_file($path)) {
                        $metadata = json_decode((string) file_get_contents($path), true);
                        break;
                    }
                }
                $class = is_array($metadata) ? ($metadata['class'] ?? null) : null;
                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    continue;
                }
                $provider = new $class();
                if ($provider instanceof ShippingRateProviderInterface) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }
}
