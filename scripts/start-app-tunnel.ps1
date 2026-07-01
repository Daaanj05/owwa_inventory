#Requires -Version 5.1
<#
.SYNOPSIS
    Start a Cloudflare quick tunnel for the Laravel app (default port 8000).
    Use with full-app-on-laptop fallback mode. Copies URL to clipboard when detected.

.EXAMPLE
    php artisan serve
    .\scripts\start-app-tunnel.ps1
#>
param(
    [int] $Port = 8000
)

$ErrorActionPreference = 'Stop'

function Get-CloudflaredPath {
    $command = Get-Command cloudflared -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    $defaultPath = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
    if (Test-Path $defaultPath) {
        return $defaultPath
    }

    throw 'cloudflared not found. Install with: winget install Cloudflare.cloudflared'
}

function Show-TunnelUrl {
    param([string] $Url, [string] $Label)

    Write-Host ''
    Write-Host "========== $Label ==========" -ForegroundColor Green
    Write-Host $Url -ForegroundColor Cyan
    Write-Host ('=' * ($Label.Length + 22)) -ForegroundColor Green
    Write-Host ''

    try {
        Set-Clipboard -Value $Url
        Write-Host 'Copied to clipboard.' -ForegroundColor Yellow
    } catch {
        Write-Host 'Could not copy to clipboard automatically - copy the URL above manually.' -ForegroundColor Yellow
    }
}

$configPath = Join-Path $env:USERPROFILE '.cloudflared\config.yml'
if (Test-Path $configPath) {
    Write-Host 'Warning: A named tunnel config exists at:' -ForegroundColor Yellow
    Write-Host "  $configPath"
    Write-Host 'Quick tunnels may fail while config.yml is present. Rename it to config.yml.bak if needed.'
    Write-Host ''
}

$cloudflared = Get-CloudflaredPath
$urlPattern = 'https://[\w-]+\.trycloudflare\.com'
$urlCopied = $false

Write-Host "Starting app quick tunnel on http://localhost:$Port ..." -ForegroundColor Cyan
Write-Host 'Ensure php artisan serve (or Laragon) is running on this port first.' -ForegroundColor DarkGray
Write-Host 'After URL appears: set APP_URL in .env, run php artisan config:clear, share URL with testers.' -ForegroundColor DarkGray
Write-Host 'Press Ctrl+C to stop.' -ForegroundColor DarkGray
Write-Host 'Waiting for tunnel URL (usually 5-15 seconds)...' -ForegroundColor DarkGray
Write-Host ''

$arguments = @(
    'tunnel',
    '--loglevel', 'info',
    '--url', "http://localhost:$Port"
)

$previousErrorAction = $ErrorActionPreference
$ErrorActionPreference = 'Continue'

try {
    & $cloudflared @arguments 2>&1 | ForEach-Object {
        $line = $_.ToString()

        if ($line -match $urlPattern) {
            if (-not $urlCopied) {
                $urlCopied = $true
                Show-TunnelUrl -Url $Matches[0] -Label 'APP TUNNEL URL (set APP_URL + share with testers)'
                Write-Host 'Then run: php artisan config:clear' -ForegroundColor Yellow
                Write-Host 'OLLAMA_URL can stay http://127.0.0.1:11434 when the app runs on this laptop.' -ForegroundColor DarkGray
                Write-Host ''
            }

            return
        }

        if ($line -match '\s(WRN|ERR)\s') {
            Write-Host $line -ForegroundColor Yellow
        }
    }
} finally {
    $ErrorActionPreference = $previousErrorAction
}

if (-not $urlCopied) {
    Write-Host 'Tunnel ended without a trycloudflare URL. Check the app is running on port 8000.' -ForegroundColor Red
    exit 1
}
