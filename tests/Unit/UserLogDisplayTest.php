<?php

namespace Tests\Unit;

use App\Support\UserLogDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserLogDisplayTest extends TestCase
{
    public function test_browser_label_detects_chrome_on_windows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

        $this->assertSame('Google Chrome on Windows', UserLogDisplay::browserLabel($ua));
    }

    public function test_path_label_hides_livewire_update_noise(): void
    {
        $this->assertSame(
            'System Admin — panel',
            UserLogDisplay::pathLabel('livewire-d2d0719e/update', 'system-admin'),
        );
    }

    #[DataProvider('loginPathProvider')]
    public function test_resolve_login_path(string $requestPath, ?string $referer, ?string $panel, string $expected): void
    {
        $this->assertSame(
            $expected,
            UserLogDisplay::resolveLoginPath($requestPath, $referer, $panel),
        );
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string}>
     */
    public static function loginPathProvider(): array
    {
        return [
            'keeps normal path' => ['system-admin/login', null, 'system-admin', 'system-admin/login'],
            'livewire falls back to panel login' => ['livewire-abc/update', null, 'system-admin', 'system-admin/login'],
            'livewire uses referer when available' => [
                'livewire-abc/update',
                'https://example.test/system-admin/login',
                'system-admin',
                'system-admin/login',
            ],
        ];
    }

    public function test_where_label_merges_panel_and_page(): void
    {
        $this->assertSame(
            'System Admin — Login',
            UserLogDisplay::whereLabel('system-admin/login', 'system-admin'),
        );
    }
}
