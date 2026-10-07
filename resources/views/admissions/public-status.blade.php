@extends('layouts.landing-bootstrap', ['title' => 'Application status — Servitech'])

@section('content')
    <main class="tala-status-page" id="main-content">
        <a class="text-link" href="{{ route('home') }}">Back to school home</a>
        <header class="tala-status-heading">
            <x-tala-panel-brand crest="outlined" />
            <h1>Application status</h1>
        </header>
        @if ($projection)
            <p><x-application-reference :value="$reference" /></p>
            <h2>{{ $projection['status'] }}</h2>
            <p>{{ $projection['next_action'] }}</p>
            <p><strong>School guidance:</strong> {{ $projection['office'] }}</p>
            <p class="text-body-secondary">This private view shows the current status. Sign in for your documents, messages, and application actions.</p>
        @else
            <h2>This status link is unavailable</h2>
            <p>It may have expired or your account details may have changed. Request a new link using your current verified email.</p>
            <p><a class="btn btn-primary-custom" href="{{ route('home') }}#application-status"><x-filament::icon icon="heroicon-o-envelope" class="tala-public-icon" aria-hidden="true" /><span>Request a new link</span></a></p>
        @endif
        <a class="btn btn-secondary-custom" href="{{ route('filament.admin.auth.login') }}"><x-filament::icon icon="heroicon-o-arrow-right-end-on-rectangle" class="tala-public-icon" aria-hidden="true" /><span>Sign in</span></a>
    </main>
@endsection
