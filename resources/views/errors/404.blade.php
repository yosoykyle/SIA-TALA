@extends('errors.layout', [
    'statusCode' => 404,
    'pageTitle' => 'Page not found',
    'summary' => 'The page may have moved, the link may be incomplete, or the record may no longer be available.',
    'guidance' => 'Check the address, then return to TALA and open the page from your workspace navigation.',
])
