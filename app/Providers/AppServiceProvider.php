<?php

namespace App\Providers;

use App\Actions\Integrations\Ocr\GoogleVisionDocumentTextClient;
use App\Actions\Integrations\Ocr\GoogleVisionImageAnnotatorDocumentTextClient;
use App\Actions\Integrations\Ocr\GoogleVisionOcrTextExtractor;
use App\Actions\Integrations\Ocr\MockOcrTextExtractor;
use App\Actions\Integrations\Ocr\OcrTextExtractor;
use App\Actions\Integrations\Payments\MockPaymentGateway;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\GoogleServiceAccountCloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Http\Middleware\EnsureActiveStudentHubUser;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Section;
use App\Policies\ActivityPolicy;
use App\Policies\FacultyAvailabilityChangeRequestPolicy;
use App\Policies\FacultyAvailabilityPeriodPolicy;
use App\Policies\FacultyAvailabilitySubmissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SectionPolicy;
use App\Support\DecimalMoney;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleVisionDocumentTextClient::class, function (): GoogleVisionDocumentTextClient {
            return new GoogleVisionImageAnnotatorDocumentTextClient(
                credentialsPath: config('tala_integrations.ocr.google_vision.credentials_path') !== null
                    ? (string) config('tala_integrations.ocr.google_vision.credentials_path')
                    : null,
                projectId: config('tala_integrations.ocr.google_vision.project_id') !== null
                    ? (string) config('tala_integrations.ocr.google_vision.project_id')
                    : null,
            );
        });

        $this->app->singleton(OcrTextExtractor::class, function ($app): OcrTextExtractor {
            return match (config('tala_integrations.ocr.driver', 'mock')) {
                'mock' => new MockOcrTextExtractor(
                    engine: (string) config('tala_integrations.ocr.mock.engine', 'mock_vision'),
                    text: (string) config('tala_integrations.ocr.mock.text', 'Mock OCR text extracted from the uploaded document.'),
                    confidence: config('tala_integrations.ocr.mock.confidence') !== null
                        ? (string) config('tala_integrations.ocr.mock.confidence')
                        : null,
                    confidenceThreshold: (string) config('tala_integrations.ocr.confidence_threshold', '80.00'),
                ),
                'google_vision' => new GoogleVisionOcrTextExtractor(
                    client: $app->make(GoogleVisionDocumentTextClient::class),
                    filesystem: $app->make(FilesystemFactory::class),
                    cache: $app->make('cache.store'),
                    confidenceThreshold: (string) config('tala_integrations.ocr.confidence_threshold', '80.00'),
                    monthlyCallLimit: (int) config('tala_integrations.ocr.google_vision.monthly_call_limit', 2000),
                ),
                default => throw new InvalidArgumentException('Unsupported TALA OCR driver configured.'),
            };
        });

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
        Livewire::addPersistentMiddleware([
            EnsureActiveStudentHubUser::class,
        ]);

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Section::class, SectionPolicy::class);
        Gate::policy(FacultyAvailabilityPeriod::class, FacultyAvailabilityPeriodPolicy::class);
        Gate::policy(FacultyAvailabilityChangeRequest::class, FacultyAvailabilityChangeRequestPolicy::class);
        Gate::policy(FacultyAvailabilitySubmission::class, FacultyAvailabilitySubmissionPolicy::class);

        Blade::component('layouts.guest', 'guest-layout');
        Blade::component('layouts.app', 'app-layout');
        Blade::component('components.guest-navbar', 'guest-navbar');
        Blade::component('components.student-navbar', 'student-navbar');
    }
}
