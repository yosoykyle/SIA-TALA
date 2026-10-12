<?php

namespace Tests\Feature\Communications;

use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Mail\AcademicRecordChangedMail;
use App\Mail\AdmissionsTransactionalMail;
use App\Mail\ApplicantStatusChangedMail;
use App\Mail\FacultyAvailabilityRequestedMail;
use App\Mail\OfficialEnrollmentMail;
use App\Mail\PaymentPostedMail;
use App\Mail\ScheduleReleasedMail;
use App\Mail\ScheduleRevisionMail;
use App\Mail\TestConnectionMail;
use App\Models\ApplicantIntake;
use App\Models\OperationalEvent;
use App\Models\PendingEmailChange;
use App\Models\StaffInvitation;
use App\Notifications\AccountAccessChangedNotification;
use App\Notifications\EmailChangeAlertNotification;
use App\Notifications\PendingEmailChangeNotification;
use App\Notifications\StaffInvitationNotification;
use Tests\TestCase;

class SchoolFirstCommunicationsTest extends TestCase
{
    public function test_academic_record_changed_mail_subjects_lead_with_institution_name(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        $conferralMail = new AcademicRecordChangedMail(
            operationalEventId: 1,
            operationalEventType: OperationalEvent::TypeConferralRecordedEmail,
            deliveryAttemptId: 'attempt-1',
            recipientName: 'Student Juan',
            changeLabel: 'Conferral Recorded',
            actionUrl: 'https://example.com/student/academics',
        );

        $this->assertSame("{$institution} — Conferral recorded", $conferralMail->envelope()->subject);

        $actionMail = new AcademicRecordChangedMail(
            operationalEventId: 2,
            operationalEventType: OperationalEvent::TypeCompletionRequiresActionEmail,
            deliveryAttemptId: 'attempt-2',
            recipientName: 'Student Juan',
            changeLabel: 'Action Required',
            actionUrl: 'https://example.com/student/academics',
        );

        $this->assertSame("{$institution} — Completion requires action", $actionMail->envelope()->subject);

        $defaultMail = new AcademicRecordChangedMail(
            operationalEventId: 3,
            operationalEventType: 'grade_released_email',
            deliveryAttemptId: 'attempt-3',
            recipientName: 'Student Juan',
            changeLabel: 'Grade Released',
            actionUrl: 'https://example.com/student/academics',
        );

        $this->assertSame("{$institution} — Academic record updated", $defaultMail->envelope()->subject);
    }

    public function test_applicant_status_changed_mail_subjects_lead_with_institution_name(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        $actionMail = new ApplicantStatusChangedMail(
            operationalEventId: 10,
            applicantIntakeId: 101,
            recipientName: 'Applicant Maria',
            status: ApplicantIntake::StatusActionRequired,
            statusLabel: 'Action Required',
            guidance: 'Action needed.',
            actionUrl: 'https://example.com/applicant',
            operationalEventType: 'applicant_action_required_email',
            programLabel: 'BSIS',
            termLabel: 'First Semester',
            responsibleOffice: 'Registrar',
            nextAction: 'Review documents.',
        );

        $this->assertSame("{$institution} — Action required for your application", $actionMail->envelope()->subject);

        $handoverMail = new ApplicantStatusChangedMail(
            operationalEventId: 11,
            applicantIntakeId: 102,
            recipientName: 'Applicant Maria',
            status: ApplicantIntake::StatusApproved,
            statusLabel: 'Approved',
            guidance: 'Handover ready.',
            actionUrl: 'https://example.com/applicant',
            operationalEventType: 'applicant_ready_email',
            programLabel: 'BSIS',
            termLabel: 'First Semester',
            responsibleOffice: 'Registrar',
            nextAction: 'Enroll now.',
        );

        $this->assertSame("{$institution} — Your application is approved for handover", $handoverMail->envelope()->subject);
    }

    public function test_staff_notifications_lead_with_institution_name(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        $invitation = (new StaffInvitation)->forceFill(['id' => 1]);
        $staffNotif = new StaffInvitationNotification($invitation, 'token-123');
        $mail = $staffNotif->toMail(new \stdClass);
        $this->assertSame("{$institution} — Activate your Staff access", $mail->subject);
        $this->assertStringStartsWith("{$institution} — ", $mail->subject);
        $this->assertSame("{$institution} — Staff access invitation", $mail->greeting);

        $accountNotif = new AccountAccessChangedNotification('Security credentials were updated.');
        $accountMail = $accountNotif->toMail(new \stdClass);
        $this->assertSame("{$institution} — Your account access was updated", $accountMail->subject);
        $this->assertStringStartsWith("{$institution} — ", $accountMail->subject);

        $emailAlertNotif = new EmailChangeAlertNotification('new@example.com');
        $alertMail = $emailAlertNotif->toMail(new \stdClass);
        $this->assertSame("{$institution} — A sign-in email change was requested", $alertMail->subject);
        $this->assertStringStartsWith("{$institution} — ", $alertMail->subject);

        $change = (new PendingEmailChange)->forceFill(['id' => 1]);
        $pendingNotif = new PendingEmailChangeNotification($change, 'token-456');
        $pendingMail = $pendingNotif->toMail(new \stdClass);
        $this->assertSame("{$institution} — Verify your new sign-in email", $pendingMail->subject);
        $this->assertStringStartsWith("{$institution} — ", $pendingMail->subject);
    }

    public function test_official_output_layout_defaults_to_school_crest_when_logo_src_is_omitted(): void
    {
        $view = $this->blade(
            '<x-official-output-layout title="Certificate of Test" classification="OFFICIAL DOCUMENT">
                <p>Output Body Content</p>
            </x-official-output-layout>'
        );

        $view->assertSee('images/brand/servitech-crest.webp', false)
            ->assertSee('alt="'.config('institution.name').' logo"', false)
            ->assertSee(config('institution.name'))
            ->assertSee('Generated through TALA from authenticated records.');
    }

    public function test_panel_brand_component_renders_concise_accessible_name_without_duplication(): void
    {
        $view = $this->blade('<x-tala-panel-brand />');

        $view->assertSee(asset('images/brand/servitech-crest.webp'), false)
            ->assertSee('alt="" aria-hidden="true" class="tala-brand__crest"', false)
            ->assertDontSee('tala-brand__star', false)
            ->assertDontSee('Powered by TALA')
            ->assertDontSee('alt="Servitech Institute Asia"', false);

        $document = new \DOMDocument;
        @$document->loadHTML((string) $view);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//span[@class="tala-brand__name"][normalize-space(.)="Servitech Institute Asia"]')->length);

        $this->blade('<x-tala-panel-brand placement="attribution" />')
            ->assertSee('Powered by TALA')
            ->assertSee('alt="" aria-hidden="true" class="tala-brand__star"', false)
            ->assertDontSee('tala-brand__crest', false);
    }

    public function test_connection_mail_identity_is_configurable_with_sentinel_name(): void
    {
        config()->set('institution.name', 'Sentinel Polytechnic Academy');
        config()->set('app.name', 'TALA Platform');

        $mail = new TestConnectionMail;
        $this->assertSame('Sentinel Polytechnic Academy — Mail Connection Test (Powered by TALA Platform)', $mail->envelope()->subject);
        $this->assertStringContainsString('Sentinel Polytechnic Academy', $mail->content()->htmlString);
        $this->assertStringContainsString('(Powered by TALA Platform)', $mail->content()->htmlString);
    }

    public function test_representative_messages_render_school_first_presentation_with_secondary_tala_attribution(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $app = (string) config('app.name', 'TALA');

        // 1. Academic Mail
        $academicMail = new AcademicRecordChangedMail(
            operationalEventId: 1,
            operationalEventType: OperationalEvent::TypeConferralRecordedEmail,
            deliveryAttemptId: 'attempt-1',
            recipientName: 'Student Juan',
            changeLabel: 'Conferral Recorded',
            actionUrl: 'https://example.com/student/academics',
        );
        $renderedAcademic = $academicMail->render();
        $this->assertStringContainsString("{$institution} via {$app}", $renderedAcademic);

        // 2. Admissions Transactional Mail
        $admissionsMail = new AdmissionsTransactionalMail(
            operationalEventId: 2,
            operationalEventType: OperationalEvent::TypeAdmissionApplicationSubmitted,
            subjectLine: "{$institution} — Application received",
            heading: 'Application received',
            safeLines: ['Application reference: APP-123', 'Your submitted version is preserved.'],
            actionLabel: 'View your application',
            actionUrl: 'https://example.com/applicant/application',
        );
        $renderedAdmissions = $admissionsMail->render();
        $this->assertStringContainsString("{$institution} via {$app}", $renderedAdmissions);

        // 3. Applicant Status Mail
        $applicantMail = new ApplicantStatusChangedMail(
            operationalEventId: 3,
            applicantIntakeId: 101,
            recipientName: 'Applicant Maria',
            status: ApplicantIntake::StatusActionRequired,
            statusLabel: 'Action Required',
            guidance: 'Action needed.',
            actionUrl: 'https://example.com/applicant',
            operationalEventType: 'applicant_action_required_email',
            programLabel: 'BSIS',
            termLabel: 'First Semester',
            responsibleOffice: 'Registrar',
            nextAction: 'Review documents.',
        );
        $renderedApplicant = $applicantMail->render();
        $this->assertStringContainsString("{$institution} via {$app}", $renderedApplicant);
        $this->assertStringContainsString('Open your application', $renderedApplicant);
        $this->assertStringNotContainsString('Applicant Workspace', $renderedApplicant);

        // 4. Staff Notification
        $invitation = (new StaffInvitation)->forceFill(['id' => 1]);
        $staffNotif = new StaffInvitationNotification($invitation, 'token-123');
        $renderedNotif = (string) $staffNotif->toMail(new \stdClass)->render();
        $this->assertStringContainsString($institution, $renderedNotif);
        $this->assertStringContainsString("{$institution} via {$app}", $renderedNotif);

        // 5. Connection Test Mail
        $connectionMail = new TestConnectionMail;
        $this->assertStringContainsString($institution, $connectionMail->content()->htmlString);
        $this->assertStringContainsString("(Powered by {$app})", $connectionMail->content()->htmlString);
    }

    public function test_shared_markdown_mail_chrome_renders_school_first_header_and_footer(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $app = (string) config('app.name', 'TALA');

        $mail = new AdmissionsTransactionalMail(
            operationalEventId: 99,
            operationalEventType: OperationalEvent::TypeAdmissionApplicationSubmitted,
            subjectLine: "{$institution} — Application received",
            heading: 'Application received',
            safeLines: ['Application reference: APP-2026-0001', 'Your submitted version is preserved.'],
            actionLabel: 'View your application',
            actionUrl: 'https://example.com/applicant/application',
        );

        $rendered = $mail->render();

        // 1. Header asserts institution leads and school crest is present
        $this->assertStringContainsString('images/brand/servitech-crest.webp', $rendered);
        $this->assertStringContainsString($institution, $rendered);
        $this->assertStringContainsString("Powered by {$app}", $rendered);

        // 2. Footer asserts institution is copyright holder and secondary TALA attribution
        $currentYear = date('Y');
        $this->assertStringContainsString("© {$currentYear} {$institution} · Powered by {$app}. All rights reserved.", $rendered);

        // 3. Negative assertion: TALA-only copyright is absent
        $this->assertStringNotContainsString("© {$currentYear} {$app}. All rights reserved.", $rendered);
    }

    public function test_additional_mailables_lead_with_institution_name(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        $facultyMail = new FacultyAvailabilityRequestedMail(
            operationalEventId: 1,
            recipientName: 'Prof. Juan',
            termLabel: 'First Semester',
            dueAt: '2026-10-01',
            availabilityUrl: 'https://example.com/availability',
        );
        $this->assertSame("{$institution} — Action required: declare your teaching availability", $facultyMail->envelope()->subject);

        $officialEnrollmentMail = new OfficialEnrollmentMail(
            operationalEventId: 2,
            recipientName: 'Student Maria',
            termLabel: 'First Semester',
            corUrl: 'https://example.com/cor',
        );
        $this->assertSame("{$institution} — Your official enrollment is confirmed", $officialEnrollmentMail->envelope()->subject);

        $paymentPostedMail = new PaymentPostedMail(
            operationalEventId: 3,
            recipientName: 'Student Maria',
            amount: 'PHP 5,000.00',
            termLabel: 'First Semester',
            financeUrl: 'https://example.com/finance',
        );
        $this->assertSame("{$institution} — Payment posted to your student ledger", $paymentPostedMail->envelope()->subject);

        $scheduleReleasedMail = new ScheduleReleasedMail(
            operationalEventId: 4,
            recipientName: 'Student Maria',
            termLabel: 'First Semester',
            scheduleUrl: 'https://example.com/schedule',
        );
        $this->assertSame("{$institution} — Your class schedule is available", $scheduleReleasedMail->envelope()->subject);

        $scheduleRevisionMail = new ScheduleRevisionMail(
            operationalEventId: 5,
            recipientName: 'Student Maria',
            revisionPayload: ['changes' => [], 'affected_enrollments' => []],
        );
        $this->assertSame("{$institution} — Your published class schedule was updated", $scheduleRevisionMail->envelope()->subject);
    }

    public function test_registration_notification_ledger_subjects_lead_with_institution_name(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        $ref = new \ReflectionClass(RegistrationNotificationLedger::class);
        $method = $ref->getMethod('mailContent');
        $method->setAccessible(true);
        $ledger = app(RegistrationNotificationLedger::class);

        $eventTypes = [
            OperationalEvent::TypeEnrollmentWindowEmail => "{$institution} — Enrollment is available for your Term",
            OperationalEvent::TypeRegistrationProposalEmail => "{$institution} — Your registration proposal is ready",
            OperationalEvent::TypeRegistrationPaymentActionEmail => "{$institution} — Registration finance action is required",
            OperationalEvent::TypeRegistrationCaseExpiryEmail => "{$institution} — Your registration reservation was released",
            OperationalEvent::TypeRegistrationAdjustmentEmail => "{$institution} — Your official enrollment was adjusted",
            OperationalEvent::TypeCourseDropEmail => "{$institution} — Your official Course Drop was recorded",
        ];

        foreach ($eventTypes as $type => $expectedSubject) {
            $event = (new OperationalEvent)->forceFill([
                'id' => 1,
                'event_type' => $type,
                'payload' => [],
            ]);

            /** @var array{subject:string,heading:string,message:string,action_label:string} $content */
            $content = $method->invoke($ledger, $event);

            $this->assertSame($expectedSubject, $content['subject']);
            $this->assertStringStartsWith("{$institution} — ", $content['subject']);
        }
    }

    public function test_public_landing_navbar_branding_uses_single_contrast_group(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // Must have data-navbar-contrast-target on landing-brand-text
        $this->assertMatchesRegularExpression('/class="[^"]*landing-brand-text[^"]*"\s+data-navbar-contrast-target/', $response->getContent());

        // Children must not have separate conflicting targets
        $this->assertStringNotContainsString('landing-brand-name" data-navbar-contrast-target', $response->getContent());
        $this->assertStringNotContainsString('landing-attribution" data-navbar-contrast-target', $response->getContent());

        // The outlined screen crest replaces the boxed plate on the public surface
        $response->assertSee(asset('images/brand/servitech-crest-outlined-192.webp'), false);
        $response->assertDontSee('landing-crest-plate', false);
    }

    public function test_shared_markdown_mail_header_has_single_meaningful_link_and_accessible_footer_contrast(): void
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $app = (string) config('app.name', 'TALA');

        $mail = new AdmissionsTransactionalMail(
            operationalEventId: 99,
            operationalEventType: OperationalEvent::TypeAdmissionApplicationSubmitted,
            subjectLine: "{$institution} — Application received",
            heading: 'Application received',
            safeLines: ['Application reference: APP-2026-0001'],
            actionLabel: 'View your application',
            actionUrl: 'https://example.com/applicant/application',
        );

        $rendered = $mail->render();

        // Exactly one header link exists matching app.url
        $headerMatches = [];
        preg_match_all('/<td class="header"[^>]*>[\s\S]*?<\/td>/', $rendered, $headerMatches);
        $this->assertNotEmpty($headerMatches[0]);
        $headerHtml = $headerMatches[0][0];

        $linkCount = substr_count($headerHtml, '<a href=');
        $this->assertSame(1, $linkCount, 'Email header must contain exactly one accessible interactive link.');

        // Decorative TALA mark and crest plate present in header
        $this->assertStringContainsString('images/brand/servitech-crest.webp', $headerHtml);
        $this->assertStringContainsString('talalogo.png', $headerHtml);
        $this->assertStringContainsString("Powered by {$app}", $headerHtml);

        // Footer contrast: must use accessible slate-600 #475569 instead of low-contrast #a1a1aa
        $this->assertStringContainsString('color: #475569', $rendered);
        $this->assertStringNotContainsString('color: #a1a1aa', $rendered);
    }
}
