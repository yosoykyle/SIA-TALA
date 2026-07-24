@extends('errors.layout', [
    'statusCode' => 429,
    'pageTitle' => 'Too many requests',
    'summary' => 'TALA temporarily paused requests from this connection to protect the service.',
    'guidance' => 'Wait a moment before trying again. Do not repeatedly refresh or submit the same action.',
])
