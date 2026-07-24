@extends('errors.layout', [
    'statusCode' => $exception->getStatusCode(),
    'pageTitle' => 'Service error',
    'summary' => 'A TALA service could not complete the request.',
    'guidance' => 'Return to TALA and try once more later. If the problem continues, contact the system administrator.',
])
