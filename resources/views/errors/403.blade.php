@extends('errors.layout', [
    'statusCode' => 403,
    'pageTitle' => 'Access not allowed',
    'summary' => 'Your account does not have permission to open this page or perform this action.',
    'guidance' => 'Return to TALA and sign in to the workspace assigned to your role. If you believe you should have access, contact the responsible office.',
])
