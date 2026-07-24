@extends('errors.layout', [
    'statusCode' => 503,
    'pageTitle' => 'Service temporarily unavailable',
    'summary' => 'TALA is temporarily unavailable because of maintenance or a service interruption.',
    'guidance' => 'Wait a few minutes, then return to TALA and try again. Your account is not the cause of this interruption.',
])
