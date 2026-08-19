<?php

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Http\Controllers\AdmissionApplicationAcknowledgmentController;
use App\Http\Controllers\AdmissionEvidenceDownloadController;
use App\Http\Controllers\BillingSlipController;
use App\Http\Controllers\CorPrintController;
use App\Http\Controllers\FacultySchedulePrintController;
use App\Http\Controllers\FinanceStatementController;
use App\Http\Controllers\PaymentAcknowledgementController;
use App\Http\Controllers\StudentSchedulePrintController;
use App\Http\Controllers\TimetableVersionPrintController;
use App\Models\FaqEntry;
use Illuminate\Support\Facades\Route;

Route::get('/', function (ApplicantEntryReadinessService $applicantEntryReadinessService) {
    return view('welcome', [
        'faqEntries' => FaqEntry::query()->publishedOrdered()->get(),
        'admissionsOpen' => $applicantEntryReadinessService->admissionsAreOpen(),
        'applicantEntryReady' => $applicantEntryReadinessService->registrationIsAvailable(),
        'officialReferences' => $applicantEntryReadinessService->officialReferences(),
        'admissionCycle' => $applicantEntryReadinessService->cycleProjection(),
    ]);
})->name('home');

Route::get('/outputs/cor/{enrollment}', CorPrintController::class)
    ->middleware('auth')
    ->name('cor.print');

Route::middleware('auth')->group(function (): void {
    Route::get(
        '/outputs/applications/{application}/acknowledgment/{version}',
        AdmissionApplicationAcknowledgmentController::class,
    )->name('admissions.application.acknowledgment');
    Route::get('/admissions/evidence/{evidence}', AdmissionEvidenceDownloadController::class)
        ->name('admissions.evidence.download');
    Route::get('/outputs/finance/statement/{assessment}', FinanceStatementController::class)
        ->name('finance.statement');
    Route::get('/outputs/finance/billing-slip/{assessment}', BillingSlipController::class)
        ->name('finance.billing-slip');
    Route::get('/outputs/finance/payment-acknowledgement/{payment}', PaymentAcknowledgementController::class)
        ->name('finance.payments.acknowledgement');
    Route::get('/outputs/schedules/faculty', FacultySchedulePrintController::class)
        ->name('faculty.schedule.print');
    Route::get('/outputs/schedules/student', StudentSchedulePrintController::class)
        ->name('student.schedule.print');
    Route::get('/outputs/schedules/timetable/{version}', TimetableVersionPrintController::class)
        ->name('timetable.version.print');
});
