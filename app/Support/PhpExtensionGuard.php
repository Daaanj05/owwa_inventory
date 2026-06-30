<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class PhpExtensionGuard
{
    public static function ensureZipArchive(): void
    {
        if (class_exists(ZipArchive::class)) {
            return;
        }

        throw new RuntimeException(
            'OWWA Excel exports require the PHP zip extension (ext-zip). '
            .'Enable extension=zip in the php.ini used by your web server (PHP '.PHP_VERSION.'), not only the CLI php.ini, then restart the web server or PHP-FPM.'
        );
    }
}
