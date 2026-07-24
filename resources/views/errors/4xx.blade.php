@extends('errors.layout', [
    'statusCode' => $exception->getStatusCode(),
    'pageTitle' => 'Request could not be completed',
    'summary' => 'TALA could not complete this request in its current form.',
    'guidance' => 'Return to TALA, review the information or link you used, and try the appropriate action again.',
])
