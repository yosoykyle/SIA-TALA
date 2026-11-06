@php
    $context = ['request' => $transcriptRequest->id, 'find' => $transcriptRequest->external_request_reference];
    $backUrl = \App\Filament\Pages\CompletionAndTor::getUrl($context);
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review TOR · {{ $documentReference }}</title>
    <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css') }}">
    <style>
        * { box-sizing:border-box; } body { margin:0; background:var(--tala-canvas); color:var(--tala-text); font:14px/1.5 'Inter Variable',Arial,sans-serif; }
        .tor-review-bar { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; padding:16px 24px; border-bottom:1px solid var(--tala-edge); background:var(--tala-surface); }
        .tor-review-bar h1 { margin:0; font-size:20px; } .tor-review-bar p { margin:4px 0 0; color:var(--tala-muted); }
        .tor-review-controls { display:flex; gap:8px; flex-wrap:wrap; }
        .tor-review-controls :is(a,button) { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:8px 14px; font:inherit; font-weight:600; text-decoration:none; color:var(--tala-text); background-color:var(--tala-surface); background-image:var(--tala-control-gloss); border:1px solid var(--tala-control-border); border-radius:8px; box-shadow:var(--tala-control-depth); cursor:pointer; }
        .tor-review-controls .tor-review-confirm { background-color:var(--tala-brand-strong); border-color:var(--tala-brand-strong); color:var(--tala-on-brand); }
        .tor-review-controls :is(a,button):focus-visible { outline:2px solid var(--tala-focus); outline-offset:3px; }
        .tor-review-frame { display:block; width:100%; height:calc(100dvh - 118px); min-height:440px; border:0; }
        .tor-review-note { margin:0; padding:8px 24px; color:var(--tala-muted); font-size:12px; }
        @media(max-width:600px) { .tor-review-bar { padding:12px; } .tor-review-controls { width:100%; } .tor-review-controls > * { flex:1 1 auto; } .tor-review-frame { height:75dvh; } }
    </style>
    <script>if(localStorage.getItem('theme')==='dark'||(!localStorage.getItem('theme')&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');</script>
</head><body>
    <header class="tor-review-bar"><div><h1>{{ str($status)->headline() }}</h1><p>{{ $documentReference }} · Transcript of records</p></div><nav class="tor-review-controls" aria-label="Document actions"><a href="{{ $backUrl }}">Back to request</a><button type="button" onclick="document.getElementById('tor-paper').contentWindow.print()">Print or save PDF</button>@if ($confirmationAction)<a class="tor-review-confirm" href="{{ \App\Filament\Pages\CompletionAndTor::getUrl([...$context,'confirm'=>$confirmationAction]) }}">{{ $confirmationAction === 'issue' ? 'Continue to issuance' : 'Continue to replacement' }}</a>@endif</nav></header>
    <p class="tor-review-note">{{ $confirmationAction ? 'Review the document below. Continuing opens a confirmation; this preview does not issue the TOR.' : 'Retained document. Its recorded certification and academic results remain unchanged.' }}</p>
    <iframe id="tor-paper" class="tor-review-frame" title="Transcript document" srcdoc="{{ $documentHtml }}"></iframe>
</body></html>
