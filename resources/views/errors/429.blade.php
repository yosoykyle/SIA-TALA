@extends('errors.layout', [
    'statusCode' => 429,
    'pageTitle' => 'Too many requests',
    'summary' => 'Requests from this connection were temporarily paused to protect the service.',
    'guidance' => 'Wait a moment before trying again. Do not repeatedly refresh or submit the same action.',
])
