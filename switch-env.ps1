#Requires -Version 5.1
<#
.SYNOPSIS
    Switch the active Laravel .env between local MySQL dev and UAT queue-worker profiles.

.EXAMPLE
    .\switch-env.ps1 mysql-local
    .\switch-env.ps1 uat-worker
    .\switch-env.ps1 status
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('mysql-local', 'uat-worker', 'status')]
    [string] $Profile
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = $PSScriptRoot
$EnvFile = Join-Path $ProjectRoot '.env'
$BackupFile = Join-Path $ProjectRoot '.env.backup'

$Profiles = @{
    'mysql-local' = @{
        Source      = Join-Path $ProjectRoot '.env.mysql.local'
        Example     = Join-Path $ProjectRoot '.env.mysql.local.example'
        Description = 'Local Laragon / MySQL development'
        NextSteps   = @(
            'Run Laragon or: composer run dev'
            'APP_URL should match your local site (e.g. http://capstoneproject.test:8080)'
        )
    }
    'uat-worker'  = @{
        Source      = Join-Path $ProjectRoot '.env.uat-worker'
        Example     = Join-Path $ProjectRoot '.env.uat-worker.example'
        Description = 'UAT mail worker (Render website + Neon + queue:work on laptop)'
        NextSteps   = @(
            'Website stays on Render - share the Render URL with testers'
            'Run: php artisan queue:work --verbose'
            'Keep this terminal open while testers need email delivery'
        )
    }
}

function Write-ProfileStatus {
    if (-not (Test-Path $EnvFile)) {
        Write-Host 'No active .env file found.' -ForegroundColor Yellow
        return
    }

    $content = Get-Content $EnvFile -Raw

    $detected = 'unknown'
    if ($content -match 'DB_CONNECTION=mysql') {
        $detected = 'mysql-local (likely)'
    } elseif ($content -match 'DB_CONNECTION=pgsql') {
        $detected = 'uat-worker (likely)'
    }

    Write-Host "Active .env profile: $detected" -ForegroundColor Cyan

    if ($content -match '(?m)^APP_URL=(.+)$') {
        Write-Host "APP_URL=$($Matches[1].Trim())"
    }

    if ($content -match '(?m)^QUEUE_CONNECTION=(.+)$') {
        Write-Host "QUEUE_CONNECTION=$($Matches[1].Trim())"
    }

    if ($content -match '(?m)^DB_CONNECTION=(.+)$') {
        Write-Host "DB_CONNECTION=$($Matches[1].Trim())"
    }
}

if ($Profile -eq 'status') {
    Write-ProfileStatus
    exit 0
}

$selected = $Profiles[$Profile]

if (-not (Test-Path $selected.Source)) {
    Write-Host "Missing profile file: $($selected.Source)" -ForegroundColor Red
    Write-Host "Create it from the example:" -ForegroundColor Yellow
    Write-Host "  copy `"$($selected.Example)`" `"$($selected.Source)`""
    Write-Host 'Then fill in your secrets (DB password, Gmail app password, APP_KEY, etc.).'
    exit 1
}

if (Test-Path $EnvFile) {
    Copy-Item -Path $EnvFile -Destination $BackupFile -Force
    Write-Host "Backed up current .env to .env.backup"
}

Copy-Item -Path $selected.Source -Destination $EnvFile -Force
Write-Host "Switched to profile: $Profile - $($selected.Description)" -ForegroundColor Green

Push-Location $ProjectRoot
try {
    php artisan config:clear | Out-Host
} finally {
    Pop-Location
}

Write-Host ''
Write-Host 'Next steps:' -ForegroundColor Cyan
foreach ($step in $selected.NextSteps) {
    Write-Host "  - $step"
}

Write-Host ''
Write-ProfileStatus
