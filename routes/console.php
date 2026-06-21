<?php

use App\Jobs\ProcessInstallmentOverduesJob;
use App\Jobs\ProcessPromissoryNoteDeadlinesJob;
use App\Jobs\ProcessRetentionDocumentUndertakingsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessInstallmentOverduesJob)
    ->name('installments.process-overdues')
    ->dailyAt('00:10')
    ->withoutOverlapping();

Schedule::job(new ProcessPromissoryNoteDeadlinesJob)
    ->name('promissory-notes.process-deadlines')
    ->dailyAt('00:45')
    ->withoutOverlapping();

Schedule::job(new ProcessRetentionDocumentUndertakingsJob)
    ->name('retention-documents.process-undertakings')
    ->dailyAt('01:00')
    ->withoutOverlapping();
