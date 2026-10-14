@extends('layouts.landing-bootstrap', ['title' => 'View your application — Servitech'])

@section('content')
    <main class="tala-status-page" id="main-content">
        <a class="text-link" href="{{ route('home') }}">Back to school home</a>
        <header class="tala-status-heading">
            <x-tala-panel-brand crest="outlined" />
            <h1>View your application</h1>
        </header>
        <p>Application updates and corrections are available through signed-in Home. Public status links have been retired.</p>
        <a class="btn btn-primary-custom" href="{{ route('filament.admin.auth.login') }}">
            <x-filament::icon icon="heroicon-o-arrow-right-end-on-rectangle" class="tala-public-icon" aria-hidden="true" />
            <span>Sign in</span>
        </a>
    </main>
@endsection
