<?php
    declare(strict_types=1);

    require_once __DIR__ . '/path.php';
    require_once __DIR__ . '/vendor/autoload.php';
    require_once FUNCTIONS . 'bootstrap.php';

    // the session lives in var/sessions, not the system's folder: the OS cleaner there deletes it after
    // 24 minutes. It lasts what Configurações → Segurança do admin says; PHP cleans its own folder.
    $sessionSeconds = (int) \Iter\Core\Config::get('admin/security/session_minutes') * 60;
    is_dir(getPath('var') . 'sessions') || mkdir(getPath('var') . 'sessions', 0700, true);
    // Configurações → Geral → "Usar só HTTPS": http is redirected (a GET) or refused (anything else: a password
    // or token must not have crossed in clear text), and HSTS tells the browser to never try http again
    $https = \Iter\Core\RateLimit::https();
    if (\Iter\Core\Config::get('web/secure/force_https')) {
        if (!$https) {
            if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
                header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            } else {
                http_response_code(403);
                echo 'Use HTTPS.';
            }
            exit;
        }
        header('Strict-Transport-Security: max-age=31536000');
    }
    // SameSite=Lax: cross-site POSTs arrive without the session cookie; Secure: over HTTPS, never sent over http;
    // strict mode: an id the server never issued (one planted by an attacker) gets a new one instead of being adopted
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => $https, 'use_strict_mode' => true, 'save_path' => getPath('var') . 'sessions',
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

