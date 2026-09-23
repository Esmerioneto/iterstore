<?php
    declare(strict_types=1);

    function getPath(string $folder): string{
        return realpath(__DIR__) . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
    }

    function getSrcPath(): string{
        return getPath('src');
    }

    function getAppPath(): string{
        return getPath('src') . 'app' . DIRECTORY_SEPARATOR;
    }

    function getEtcPath(): string{
        return getPath('src') . 'etc' . DIRECTORY_SEPARATOR;
    }

    function getVendorPath(): string{
        return getPath('src') . 'vendor' . DIRECTORY_SEPARATOR;
    }

    function getCodePath(): string{
        return getAppPath() .'code' . DIRECTORY_SEPARATOR;
    }


    function getFunctionsPath(): string{
        return getPath('functions');
    }


    //Contants of path
    define('SRC', getSrcPath());
    define('APP', getAppPath());
    define('CODE', getCodePath());
    define('FUNCTIONS', getFunctionsPath());
    define('ETC', getEtcPath());
    define('VENDOR', getVendorPath());

