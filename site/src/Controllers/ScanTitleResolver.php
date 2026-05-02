<?php

namespace Calibre\Controllers;

use Calibre\ScanService;
use Calibre\Support\Lang;

final class ScanTitleResolver
{
    public static function resolve(string $appRoot): string
    {
        $resolved = trim((string) ScanService::readSetting(
            ScanService::loadConfig($appRoot),
            ['SITE_TITLE'],
            'SITE_TITLE',
            Lang::t('layout.default_title')
        ));

        return $resolved !== '' ? $resolved : Lang::t('layout.default_title');
    }
}
