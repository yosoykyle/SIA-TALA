<?php

use App\Http\Controllers\AdmissionApplicationAcknowledgmentController;
use App\Http\Controllers\AdmissionEvidenceDownloadController;
use App\Http\Controllers\CorPrintController;
use App\Http\Controllers\FacultySchedulePrintController;
use App\Http\Controllers\FinanceExportDownloadController;
use App\Http\Controllers\FinanceStatementController;
use App\Http\Controllers\GradeRosterOutputController;
use App\Http\Controllers\PaymentAcknowledgementController;
use App\Http\Controllers\PaymentEvidenceDownloadController;
use App\Http\Controllers\PublicGatewayController;
use App\Http\Controllers\StaffEmailChangeController;
use App\Http\Controllers\StaffInvitationActivationController;
use App\Http\Controllers\StudentSchedulePrintController;
use App\Http\Controllers\TimetableVersionPrintController;
use App\Http\Controllers\TranscriptPreviewController;
use App\Http\Controllers\TranscriptSnapshotController;
use App\Http\Controllers\UnofficialStudentRecordController;
use App\Http\Controllers\WorkspaceContextController;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicGatewayController::class)->name('home');

Route::get('/staff-activation/{invitation}', [StaffInvitationActivationController::class, 'show'])
    ->name('staff-invitations.activate');
Route::post('/staff-activation/{invitation}', [StaffInvitationActivationController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('staff-invitations.accept');
Route::get('/staff-email-change/{change}', StaffEmailChangeController::class)
    ->middleware('throttle:5,1')
    ->name('staff-email-changes.verify');

Route::middleware('auth')->group(function (): void {
    Route::get('/workspace-chooser', [WorkspaceContextController::class, 'show'])->name('workspace-chooser');
    Route::post('/workspace-chooser', [WorkspaceContextController::class, 'store'])
        ->name('workspace-chooser.select');
});

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
    Route::get('/outputs/finance/payment-acknowledgement/{payment}', PaymentAcknowledgementController::class)
        ->name('finance.payments.acknowledgement');
    Route::get('/finance/payment-evidence/{evidence}', PaymentEvidenceDownloadController::class)
        ->name('finance.payment-evidence.download');
    Route::get('/finance/exports/{export}', FinanceExportDownloadController::class)
        ->name('finance.exports.download');
    Route::get('/outputs/schedules/faculty', FacultySchedulePrintController::class)
        ->name('faculty.schedule.print');
    Route::get('/outputs/schedules/student', StudentSchedulePrintController::class)
        ->name('student.schedule.print');
    Route::get('/outputs/schedules/timetable/{version}', TimetableVersionPrintController::class)
        ->name('timetable.version.print');
    Route::get('/outputs/grade-rosters/{roster}/print', [GradeRosterOutputController::class, 'print'])
        ->name('grade-rosters.print');
    Route::get('/outputs/grade-rosters/{roster}/csv', [GradeRosterOutputController::class, 'csv'])
        ->name('grade-rosters.csv');
    Route::get('/outputs/academics/unofficial-record/{student}', UnofficialStudentRecordController::class)
        ->name('student-academics.unofficial-record');
    Route::get('/outputs/academics/transcript/{transcriptRequest}', TranscriptPreviewController::class)
        ->name('transcripts.preview');
    Route::get('/outputs/academics/transcript-snapshots/{snapshot}', TranscriptSnapshotController::class)
        ->name('transcript-snapshots.show');
});
