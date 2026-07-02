<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OwwaTemplateLoader
{
    /**
     * @var array<string, array{mtime: int, binary: string}>
     */
    protected static array $cache = [];

    /**
     * @var list<string>
     */
    protected static array $temporaryPaths = [];

    public static function load(string $absolutePath): Spreadsheet
    {
        $mtime = filemtime($absolutePath) ?: 0;

        if (
            isset(self::$cache[$absolutePath])
            && self::$cache[$absolutePath]['mtime'] === $mtime
        ) {
            return self::loadFromBinary(self::$cache[$absolutePath]['binary']);
        }

        PhpExtensionGuard::ensureZipArchive();

        $spreadsheet = IOFactory::load($absolutePath);

        if (str_ends_with(strtolower($absolutePath), '.xlsx')) {
            self::$cache[$absolutePath] = [
                'mtime' => $mtime,
                'binary' => self::toBinary($spreadsheet),
            ];
        }

        return $spreadsheet;
    }

    public static function forget(string $absolutePath): void
    {
        unset(self::$cache[$absolutePath]);
    }

    public static function flush(): void
    {
        foreach (self::$temporaryPaths as $temporaryPath) {
            @unlink($temporaryPath);
        }

        self::$temporaryPaths = [];
        self::$cache = [];
    }

    protected static function toBinary(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    protected static function loadFromBinary(string $binary): Spreadsheet
    {
        PhpExtensionGuard::ensureZipArchive();

        $temporaryPath = tempnam(sys_get_temp_dir(), 'owwa_tpl_');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Unable to create temporary file for OWWA template cache.');
        }

        file_put_contents($temporaryPath, $binary);

        self::$temporaryPaths[] = $temporaryPath;

        return IOFactory::load($temporaryPath);
    }
}
