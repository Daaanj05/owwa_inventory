#Requires -Version 5.1
<#
.SYNOPSIS
    Start a Cloudflare quick tunnel for local Ollama (port 11434).
    Copies the trycloudflare URL to clipboard when detected.

.EXAMPLE
    .\scripts\start-ollama-tunnel.ps1
#>
param(
    [int] $Port = 11434
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

Write-Host "Starting Ollama quick tunnel on http://localhost:$Port ..." -ForegroundColor Cyan
Write-Host 'Press Ctrl+C to stop. Update Render OLLAMA_URL with the URL below.' -ForegroundColor DarkGray
Write-Host 'Waiting for tunnel URL (usually 5-15 seconds)...' -ForegroundColor DarkGray
Write-Host ''

$arguments = @(
    'tunnel',
    '--loglevel', 'info',
    '--url', "http://localhost:$Port",
    '--http-host-header', "localhost:$Port"
)

$previousErrorAction = $ErrorActionPreference
$ErrorActionPreference = 'Continue'

try {
    & $cloudflared @arguments 2>&1 | ForEach-Object {
        $line = $_.ToString()

        if ($line -match $urlPattern) {
            if (-not $urlCopied) {
                $urlCopied = $true
                Show-TunnelUrl -Url $Matches[0] -Label 'OLLAMA TUNNEL URL (copy to Render OLLAMA_URL)'
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
    Write-Host 'Tunnel ended without a trycloudflare URL. Check Ollama is running on port 11434.' -ForegroundColor Red
    exit 1
}
