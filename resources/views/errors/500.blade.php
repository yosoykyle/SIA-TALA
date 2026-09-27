@extends('errors.layout', [
    'statusCode' => 500,
    'pageTitle' => 'Something went wrong',
    'summary' => 'The request could not be completed because of an unexpected service error.',
    'guidance' => 'The request outcome is unconfirmed. Return to your workspace and check the latest recorded state before retrying. If the error continues, note what you were doing and contact System Administration.',
])
