<?php

declare(strict_types=1);

namespace Iter\Backup\View;

use Iter\Backup\Model\Backup;
use Iter\Core\Config;
use Iter\Core\View\ViewComponentInterface;

class Kit implements ViewComponentInterface
{
    /** set by the controller after the password check, for this response only */
    public static ?array $unlocked = null;

    public function getTemplate(): string
    {
        return 'backup/kit';
    }

    public function getData(): array
    {
        return [
            'kit' => self::$unlocked,
            'destination' => Backup::destinationLabel(),
            'key_id' => (string) Config::get('system/backup/key_id'),
            'dump_file' => Backup::dumpFile(),
        ];
    }
}
