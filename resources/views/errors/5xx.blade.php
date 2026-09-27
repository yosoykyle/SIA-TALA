@extends('errors.layout', [
    'statusCode' => $exception->getStatusCode(),
    'pageTitle' => 'Service error',
    'summary' => 'The service could not complete the request.',
    'guidance' => 'Return to the school home page and try once more later. If the problem continues, contact System Administration.',
])
