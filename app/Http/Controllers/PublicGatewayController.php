<?php

namespace App\Http\Controllers;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Filament\Applicant\Pages\Application;
use App\Models\AdmissionCycle;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\FaqEntry;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\Term;
use App\Models\User;
use App\Support\AuthBannerMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicGatewayController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicantEntryReadinessService $entry,
        AdmissionWindowService $windows,
        SchoolPaymentChannels $paymentChannels,
        WorkspaceContextResolver $workspaces,
    ): View {
        $data = [
            'officialReferences' => $entry->officialReferences(),
            'admissionsOpen' => false,
            'applicantEntryReady' => false,
            'admissionCycle' => null,
            'admissionState' => 'Missing',
            'openIntakeCount' => 0,
            'acceptingProgramIds' => [],
            'programs' => collect(),
            'notices' => collect(),
            'faqEntries' => collect(),
            'cashChannel' => null,
            'registrarHours' => [
                'label' => 'Registrar Office',
                'days' => 'Monday to Friday',
                'hours' => '7:00 AM – 5:00 PM',
                'note' => 'Closed on holidays',
            ],
            'workspaceAction' => $this->resolveWorkspaceAction($request->user(), $workspaces),
            'campusSlides' => AuthBannerMedia::slides('applicant.webp'),
            'unavailable' => [],
            'asOf' => now()->timezone('Asia/Manila')->format('M j, Y, g:i A'),
        ];

        try {
            $cycle = $windows->currentCycle();
            $data['admissionsOpen'] = $cycle !== null;
            $data['applicantEntryReady'] = $entry->registrationIsAvailable();
            $data['admissionCycle'] = $entry->cycleProjection();
            $data['admissionState'] = $cycle ? 'Open' : ($data['admissionCycle'] ? 'Upcoming' : 'Missing');
            if (! $cycle && ! $data['admissionCycle']) {
                $last = AdmissionCycle::query()->where('state', AdmissionCycle::StatePublished)->where('closes_at', '<=', now())->latest('closes_at')->first();
                if ($last) {
                    $data['admissionState'] = 'Closed';
                }
            }
            $data['acceptingProgramIds'] = $this->acceptingPrograms()->modelKeys();
            $data['openIntakeCount'] = AdmissionCycle::query()
                ->where('state', AdmissionCycle::StatePublished)
                ->where('opens_at', '<=', now())
                ->where('closes_at', '>', now())
                ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
                ->count();
        } catch (QueryException $exception) {
            report($exception);
            $data['admissionState'] = 'Unavailable';
            $data['admissionsOpen'] = false;
            $data['applicantEntryReady'] = false;
            $data['admissionCycle'] = null;
        }

        foreach ([
            'programs' => fn () => Program::query()->where('is_active', true)->orderBy('code')->get(),
            'notices' => fn () => PublicNotice::query()->effective()->orderBy('display_order')->orderBy('id')->get(),
            'faqEntries' => fn () => FaqEntry::query()->publishedOrdered()->get(),
        ] as $key => $project) {
            try {
                $data[$key] = $project();
            } catch (QueryException $exception) {
                report($exception);
                $data['unavailable'][] = $key;
            }
        }

        try {
            $data['cashChannel'] = $paymentChannels->forDisplay()['cashChannel'] ?? null;
        } catch (QueryException $exception) {
            report($exception);
            $data['unavailable'][] = 'cashChannel';
        }

        $allActivePrograms = $data['programs'];
        $data['totalProgramCount'] = $allActivePrograms->count();
        $data['openProgramCount'] = $allActivePrograms->whereIn('id', $data['acceptingProgramIds'])->count();
        $data['notOpenProgramCount'] = $data['totalProgramCount'] - $data['openProgramCount'];
        $data['programDurations'] = $allActivePrograms->pluck('duration_years')->unique()->sort()->values();
        $data['programDurationCounts'] = $allActivePrograms
            ->groupBy('duration_years')
            ->map(fn (Collection $group): int => $group->count())
            ->all();
        $duration = $request->integer('duration');
        $availability = $request->query('availability');
        $data['programFilters'] = array_filter([
            'duration' => $data['programDurations']->contains($duration) ? $duration : null,
            'availability' => in_array($availability, ['open', 'enquire'], true) ? $availability : null,
        ], fn (mixed $value): bool => $value !== null);
        $data['selectedProgram'] = $allActivePrograms->firstWhere('id', $request->integer('program'));
        $data['programs'] = $allActivePrograms->filter(function (Program $program) use ($data): bool {
            $accepting = in_array($program->id, $data['acceptingProgramIds'], true);

            return (! isset($data['programFilters']['duration']) || $program->duration_years === $data['programFilters']['duration'])
                && match ($data['programFilters']['availability'] ?? null) {
                    'open' => $accepting,
                    'enquire' => ! $accepting,
                    default => true,
                };
        });

        return view('welcome', $data);
    }

    public function apply(Request $request, ApplicantEntryReadinessService $entry): RedirectResponse
    {
        if (! $entry->registrationIsAvailable()) {
            return redirect()->to(route('home').'#programs')->with('status', 'Applications are currently unavailable. Contact Admissions for guidance.');
        }

        $programId = $request->integer('program');

        if ($programId > 0 && ! $this->acceptingPrograms()->contains('id', $programId)) {
            return redirect()->to(route('home').'#programs')->with('status', 'This Program is currently unavailable for application. Review the current Programs or contact Admissions.');
        }

        if ($programId > 0) {
            $request->session()->put('public_entry.program_id', $programId);
            $request->session()->put('url.intended', Application::getUrl(['program' => $programId], panel: 'applicant'));
        }

        $user = $request->user();

        if ($user instanceof User) {
            if ($user->hasVerifiedEmail() && $user->hasRole('applicant') && ! $user->hasAnyRole(['student', ...User::staffRoleNames()])) {
                return redirect()->to(Application::getUrl($programId > 0 ? ['program' => $programId] : [], panel: 'applicant'));
            }

            return redirect()->route('filament.admin.auth.login');
        }

        return redirect()->route('filament.applicant.auth.register');
    }

    /** @return Collection<int, Program> */
    private function acceptingPrograms(): Collection
    {
        $openAcademicYearIds = AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
            ->with('term:id,academic_year_id')
            ->get()
            ->pluck('term.academic_year_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $schoolUsesBatches = CurriculumVersionBatch::query()->exists();

        return Program::query()->where('is_active', true)
            ->whereHas('admissionCycles', fn (Builder $query): Builder => $query
                ->where('state', AdmissionCycle::StatePublished)
                ->where('opens_at', '<=', now())
                ->where('closes_at', '>', now())
                ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
                ->where(fn (Builder $eligible): Builder => $eligible
                    ->where('admission_cycle_program.accepts_first_year', true)
                    ->orWhere('admission_cycle_program.accepts_transferee', true)))
            ->when(
                $schoolUsesBatches && $openAcademicYearIds !== [],
                fn (Builder $query): Builder => $query->whereHas('curriculumVersionBatches', fn (Builder $batches): Builder => $batches
                    ->whereIn('academic_year_id', $openAcademicYearIds)
                    ->whereHas('curriculumVersion', fn (Builder $versions): Builder => $versions
                        ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded]))),
            )
            ->get();
    }

    /**
     * Resolve the topbar/card workspace CTA for signed-in users.
     *
     * @return array{label: string, url: string}|null
     */
    private function resolveWorkspaceAction(mixed $user, WorkspaceContextResolver $workspaces): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        try {
            $available = $workspaces->availableContexts($user);
            $selected = $workspaces->selected($user);

            if ($selected !== null && isset($available[$selected]['destination'])) {
                $url = $available[$selected]['destination'];
            } elseif (count($available) === 1) {
                $first = reset($available);
                $url = is_array($first) ? $first['destination'] : route('public.sign-in');
            } elseif (count($available) > 1) {
                $url = route('workspace-chooser');
            } else {
                $url = route('public.sign-in');
            }
        } catch (QueryException $exception) {
            report($exception);
            $url = route('public.sign-in');
        }

        return [
            'label' => 'Open workspace',
            'url' => $url,
        ];
    }
}
