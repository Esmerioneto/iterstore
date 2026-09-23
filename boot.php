<?php
    declare(strict_types=1);

    // SameSite=Lax: cross-site POSTs arrive without the session cookie
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

    require_once __DIR__ . '/path.php';
    require_once __DIR__ . '/vendor/autoload.php';
    require_once FUNCTIONS . 'bootstrap.php';

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

