<?php

$_ENV['APP_BASE_PATH'] = dirname(__DIR__);
$_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

spl_autoload_register(function ($class): void {
    if (str_starts_with($class, 'App\\')) {
        $relative = str_replace('\\', '/', substr($class, 4));
        $file = __DIR__.'/../app/'.$relative.'.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}, prepend: true);

require __DIR__.'/../vendor/autoload.php';
