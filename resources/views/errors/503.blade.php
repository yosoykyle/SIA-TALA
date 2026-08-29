@extends('errors.layout', [
    'statusCode' => 503,
    'pageTitle' => 'Service temporarily unavailable',
    'summary' => 'TALA is temporarily unavailable because of maintenance or a service interruption.',
    'guidance' => 'Return to TALA when service is available. Check the latest recorded state before resubmitting an action. Contact System Administration if the interruption continues; no recovery time is confirmed here.',
])
