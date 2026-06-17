<?php

use App\Jobs\ProcessInstallmentOverduesJob;
use App\Jobs\ProcessPromissoryNoteDeadlinesJob;
use App\Jobs\ShippingFeeEnforcerJob;
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

Schedule::job(new ShippingFeeEnforcerJob)
    ->name('document-requests.shipping-fee-enforcer')
    ->dailyAt('00:30')
    ->withoutOverlapping();

Schedule::job(new ProcessPromissoryNoteDeadlinesJob)
    ->name('promissory-notes.process-deadlines')
    ->dailyAt('00:45')
    ->withoutOverlapping();
