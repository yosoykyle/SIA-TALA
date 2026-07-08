<?php

namespace App\Providers;

use App\Actions\Integrations\Payments\MockPaymentGateway;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\GoogleServiceAccountCloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Listeners\LogAuthenticationActivity;
use App\Models\AcademicYear;
use App\Models\AccountingAdjustment;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\DeliveryPattern;
use App\Models\DuplicateProfileResolution;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeeRule;
use App\Models\FinancialAccommodation;
use App\Models\ImportBatch;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Observers\CurriculumSubjectObserver;
use App\Policies\AcademicYearPolicy;
use App\Policies\AccountingAdjustmentPolicy;
use App\Policies\ActivityPolicy;
use App\Policies\AssessmentLinePolicy;
use App\Policies\AssessmentPolicy;
use App\Policies\CalendarEventPolicy;
use App\Policies\CoursePolicy;
use App\Policies\CourseSpecificationPolicy;
use App\Policies\CurriculumVersionPolicy;
use App\Policies\DeliveryPatternPolicy;
use App\Policies\DuplicateProfileResolutionPolicy;
use App\Policies\FacultyQualificationPolicy;
use App\Policies\FacultyTermLoadOverridePolicy;
use App\Policies\FeeRulePolicy;
use App\Policies\FinancialAccommodationPolicy;
use App\Policies\ImportBatchPolicy;
use App\Policies\LedgerEntryPolicy;
use App\Policies\PaymentAttemptPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PaymentScheduleRowPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\RolePolicy;
use App\Policies\RoomPolicy;
use App\Policies\ScheduleGenerationRunPolicy;
use App\Policies\SchedulingDemandPolicy;
use App\Policies\SectionDeliveryGroupPolicy;
use App\Policies\SectionMeetingPolicy;
use App\Policies\SectionPolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\TermPolicy;
use App\Support\DecimalMoney;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CloudRunIdTokenProvider::class, function (): CloudRunIdTokenProvider {
            return new GoogleServiceAccountCloudRunIdTokenProvider(
                credentialsPath: config('tala_integrations.scheduling_solver.credentials_path') !== null
                    ? (string) config('tala_integrations.scheduling_solver.credentials_path')
                    : null,
            );
        });

        $this->app->singleton(CloudRunSchedulingSolverClient::class, function ($app): CloudRunSchedulingSolverClient {
            return new CloudRunSchedulingSolverClient(
                idTokenProvider: $app->make(CloudRunIdTokenProvider::class),
                baseUrl: config('tala_integrations.scheduling_solver.url') !== null
                    ? (string) config('tala_integrations.scheduling_solver.url')
                    : null,
                audience: config('tala_integrations.scheduling_solver.audience') !== null
                    ? (string) config('tala_integrations.scheduling_solver.audience')
                    : null,
                timeoutSeconds: (int) config('tala_integrations.scheduling_solver.timeout_seconds', 300),
                connectTimeoutSeconds: (int) config('tala_integrations.scheduling_solver.connect_timeout_seconds', 10),
            );
        });

        $this->app->singleton(SchedulingSolverClient::class, function ($app): SchedulingSolverClient {
            return match (config('tala_integrations.scheduling_solver.driver', 'local_stub')) {
                'local_stub' => new LocalStubSchedulingSolverClient,
                'cloud_run' => $app->make(CloudRunSchedulingSolverClient::class),
                default => throw new InvalidArgumentException('Unsupported TALA scheduling solver driver configured.'),
            };
        });

        $this->app->singleton(PayMongoPaymentGateway::class, function ($app): PayMongoPaymentGateway {
            return new PayMongoPaymentGateway(
                money: $app->make(DecimalMoney::class),
                baseUrl: (string) config('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com/v1'),
                secretKey: config('tala_integrations.payments.paymongo.secret_key') !== null
                    ? (string) config('tala_integrations.payments.paymongo.secret_key')
                    : null,
                paymentMethodTypes: (array) config('tala_integrations.payments.paymongo.payment_method_types', ['gcash', 'card']),
            );
        });

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return match (config('tala_integrations.payments.driver', 'mock')) {
                'mock' => new MockPaymentGateway(
                    provider: (string) config('tala_integrations.payments.mock.provider', 'mock'),
                    checkoutBaseUrl: (string) config('tala_integrations.payments.mock.checkout_base_url', 'https://mock-payments.test/checkout'),
                ),
                'paymongo' => $app->make(PayMongoPaymentGateway::class),
                default => throw new InvalidArgumentException('Unsupported TALA payment gateway driver configured.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(AccountingAdjustment::class, AccountingAdjustmentPolicy::class);
        Gate::policy(FeeRule::class, FeeRulePolicy::class);
        Gate::policy(FinancialAccommodation::class, FinancialAccommodationPolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);
        Gate::policy(AssessmentLine::class, AssessmentLinePolicy::class);
        Gate::policy(PaymentScheduleRow::class, PaymentScheduleRowPolicy::class);
        Gate::policy(LedgerEntry::class, LedgerEntryPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PaymentAttempt::class, PaymentAttemptPolicy::class);
        Gate::policy(SchedulingDemand::class, SchedulingDemandPolicy::class);
        Gate::policy(ScheduleGenerationRun::class, ScheduleGenerationRunPolicy::class);
        Gate::policy(Section::class, SectionPolicy::class);
        Gate::policy(DeliveryPattern::class, DeliveryPatternPolicy::class);
        Gate::policy(SectionDeliveryGroup::class, SectionDeliveryGroupPolicy::class);
        Gate::policy(SectionMeeting::class, SectionMeetingPolicy::class);
        Gate::policy(Program::class, ProgramPolicy::class);
        Gate::policy(AcademicYear::class, AcademicYearPolicy::class);
        Gate::policy(Term::class, TermPolicy::class);
        Gate::policy(CalendarEvent::class, CalendarEventPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(FacultyQualification::class, FacultyQualificationPolicy::class);
        Gate::policy(FacultyTermLoadOverride::class, FacultyTermLoadOverridePolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(CourseSpecification::class, CourseSpecificationPolicy::class);
        Gate::policy(CurriculumVersion::class, CurriculumVersionPolicy::class);
        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);
        Gate::policy(DuplicateProfileResolution::class, DuplicateProfileResolutionPolicy::class);

        CurriculumSubject::observe(CurriculumSubjectObserver::class);

        Event::listen(Login::class, [LogAuthenticationActivity::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationActivity::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthenticationActivity::class, 'handleFailed']);

        Blade::component('layouts.guest', 'guest-layout');
        Blade::component('layouts.app', 'app-layout');
        Blade::component('components.guest-navbar', 'guest-navbar');
        Blade::component('components.student-navbar', 'student-navbar');
    }
}
