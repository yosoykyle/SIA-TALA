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
use App\Models\AccountingAdjustment;
use App\Models\CurriculumSubject;
use App\Models\DeliveryPattern;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Observers\CurriculumSubjectObserver;
use App\Policies\AccountingAdjustmentPolicy;
use App\Policies\ActivityPolicy;
use App\Policies\DeliveryPatternPolicy;
use App\Policies\FacultyAvailabilityChangeRequestPolicy;
use App\Policies\FacultyAvailabilityPeriodPolicy;
use App\Policies\FacultyAvailabilitySubmissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SectionDeliveryGroupPolicy;
use App\Policies\SectionPolicy;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\Blade;
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
        Gate::policy(Section::class, SectionPolicy::class);
        Gate::policy(DeliveryPattern::class, DeliveryPatternPolicy::class);
        Gate::policy(SectionDeliveryGroup::class, SectionDeliveryGroupPolicy::class);
        Gate::policy(FacultyAvailabilityPeriod::class, FacultyAvailabilityPeriodPolicy::class);
        Gate::policy(FacultyAvailabilityChangeRequest::class, FacultyAvailabilityChangeRequestPolicy::class);
        Gate::policy(FacultyAvailabilitySubmission::class, FacultyAvailabilitySubmissionPolicy::class);

        CurriculumSubject::observe(CurriculumSubjectObserver::class);

        Blade::component('layouts.guest', 'guest-layout');
        Blade::component('layouts.app', 'app-layout');
        Blade::component('components.guest-navbar', 'guest-navbar');
        Blade::component('components.student-navbar', 'student-navbar');
    }
}
