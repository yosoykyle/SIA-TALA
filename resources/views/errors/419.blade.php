@extends('errors.layout', [
    'statusCode' => 419,
    'pageTitle' => 'Your session has expired',
    'summary' => 'TALA ended this session to protect your account. Unsaved information may need to be entered again.',
    'guidance' => 'Return to TALA, sign in again, and repeat the action. Avoid submitting the same action in multiple tabs.',
])
