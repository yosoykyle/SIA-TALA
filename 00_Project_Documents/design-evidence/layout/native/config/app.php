<?php

return [
    'name' => 'TALA UI native preview',
    'env' => 'local',
    'debug' => true,
    'url' => 'http://127.0.0.1:4174',
    'timezone' => 'Asia/Manila',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => 'base64:'.base64_encode(hash('sha256', __DIR__.'/fictional-local-preview', true)),
    'cipher' => 'AES-256-CBC',
];
