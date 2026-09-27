@extends('errors.layout', [
    'statusCode' => $exception->getStatusCode(),
    'pageTitle' => 'Request could not be completed',
    'summary' => 'This request could not be completed in its current form.',
    'guidance' => 'Return to the school home page, review the information or link you used, and try the appropriate action again.',
])
