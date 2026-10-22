@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="accounting-export class-roster-export">
    <div class="accounting-export-identity">
        <p><strong>{{ $roster->section?->code }} · {{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title }}</strong></p>
        <p>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} · {{ $roster->termOffering?->term?->label }}</p>
        @if (! $error)<p>{{ number_format($count) }} current {{ str('learner')->plural($count) }}</p>@endif
    </div>
    @if ($error)
        <section class="accounting-export-empty" role="status"><h3>{{ str($error)->startsWith('No officially') ? 'No current learners' : 'Class membership changed' }}</h3><p>{{ $error }} No file has been created.</p></section>
        <p>The export uses the current official class list.</p>
    @else
        <div class="accounting-export-table-wrap" role="region" aria-label="Class roster preview" tabindex="0">
            <table class="accounting-export-table class-roster-export-table">
                <thead><tr><th scope="col">Student number</th><th scope="col">Legal name</th><th scope="col">Program</th></tr></thead>
                <tbody>@foreach ($rows as $row)<tr><th scope="row">{{ $row[5] }}</th><td>{{ $row[6] }}</td><td>{{ $row[4] }}</td></tr>@endforeach</tbody>
            </table>
        </div>
        <p class="accounting-export-note">Preview of the first {{ count($rows) }} {{ str('learner')->plural(count($rows)) }}, ordered by legal name. Current membership is checked again on download.</p>
        <details class="accounting-export-columns"><summary>CSV columns and source details · {{ count($headers) }} columns</summary>
            <ol>@foreach ($headers as $header)<li>{{ $header }}</li>@endforeach</ol>
            <p>This operational class list includes current official membership. Retained historical learners stay in roster history.</p>
        </details>
    @endif
    <p class="accounting-export-note">Current as of {{ \App\Support\DisplayDateTime::format($generatedAt, 'M j, Y · g:i A') }} (Asia/Manila).<br>Restricted to authorized class and Registrar access.</p>
</div>
