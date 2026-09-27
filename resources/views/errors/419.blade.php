@extends('errors.layout', [
    'statusCode' => 419,
    'pageTitle' => 'Your session has expired',
    'summary' => 'Your session was ended to protect your account. Unsaved information may need to be entered again.',
    'guidance' => 'Return to the Servitech Institute Asia home page, choose your workspace to sign in again, and check your latest recorded state before resubmitting. Avoid submitting forms across multiple tabs simultaneously.',
])
