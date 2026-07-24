@extends('errors.layout', [
    'statusCode' => 500,
    'pageTitle' => 'Something went wrong',
    'summary' => 'TALA could not complete the request because of an unexpected service error.',
    'guidance' => 'Return to TALA and try once more. If the error continues, note what you were doing and contact the system administrator.',
])
