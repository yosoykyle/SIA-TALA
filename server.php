<?php

use App\Actions\SystemAdministration\SystemHealthPresenter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Tests\Browser\BrowserQualificationEnvironment;

$targetDb = getenv('DB_DATABASE') ?: 'test_tala_db';
putenv("DB_DATABASE={$targetDb}");
$_ENV['DB_DATABASE'] = $targetDb;
$_SERVER['DB_DATABASE'] = $targetDb;

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

$publicDir = realpath(__DIR__.'/public');
$candidatePath = __DIR__.'/public'.$uri;
$targetPath = realpath($candidatePath);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server while sanitizing against directory traversal.
if (
    $uri !== '/' &&
    $publicDir !== false &&
    $targetPath !== false &&
    str_starts_with($targetPath, $publicDir.DIRECTORY_SEPARATOR) &&
    is_file($targetPath)
) {
    // If document root is public, delegate to built-in server
    if (isset($_SERVER['DOCUMENT_ROOT']) && realpath($_SERVER['DOCUMENT_ROOT']) === $publicDir) {
        return false;
    }

    // Otherwise serve static file directly with content-type
    $filePath = $targetPath;
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'map' => 'application/json',
    ];

    $mime = $mimes[$ext] ?? (mime_content_type($filePath) ?: 'application/octet-stream');
    header("Content-Type: {$mime}");
    header('Content-Length: '.filesize($filePath));
    header('Connection: close');
    readfile($filePath);

    return true;
}

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->bind(SystemHealthPresenter::class, function () {
    if (class_exists(BrowserQualificationEnvironment::class) && BrowserQualificationEnvironment::isHealthCaptureFailureEnabled()) {
        return new class extends SystemHealthPresenter
        {
            public function capture(): array
            {
                throw new RuntimeException('Simulated health check capture failure for browser qualification.');
            }
        };
    }

    return new SystemHealthPresenter;
});

$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('Connection', 'close');
$response->send();
$kernel->terminate($request, $response);
