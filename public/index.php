<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Cap PHP memory to the Render Starter / Free instance size (512 MB RAM).
$owwaRequestUri = $_SERVER['REQUEST_URI'] ?? '';
if (is_string($owwaRequestUri) && (
    str_contains($owwaRequestUri, '/reports/owwa/')
    || str_contains($owwaRequestUri, '/livewire')
    || str_contains($owwaRequestUri, '/admin')
)) {
    @ini_set('memory_limit', '512M');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
