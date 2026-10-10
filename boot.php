<?php
    declare(strict_types=1);

    require_once __DIR__ . '/path.php';
    require_once __DIR__ . '/vendor/autoload.php';
    require_once FUNCTIONS . 'bootstrap.php';

    // the session lives in var/sessions, not the system's folder: the OS cleaner there deletes it after
    // 24 minutes. It lasts what Configurações → Segurança do admin says; PHP cleans its own folder.
    $sessionSeconds = (int) \Iter\Core\Config::get('admin/security/session_minutes') * 60;
    is_dir(getPath('var') . 'sessions') || mkdir(getPath('var') . 'sessions', 0700, true);
    // SameSite=Lax: cross-site POSTs arrive without the session cookie
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'save_path' => getPath('var') . 'sessions',
        'gc_maxlifetime' => $sessionSeconds + 60, 'gc_probability' => 1, 'gc_divisor' => 100]);

    $modules = getModules();
    $moduleNames = array();

    foreach ($modules as $key => $module) {
        foreach ($module as $k => $value) {
            if ($value == 1) {
                $moduleNames[$k] = $k;
            }
        }
    }
    
    autoload($moduleNames);

    require_once __DIR__ . '/routes.php';

