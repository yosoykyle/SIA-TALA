<?php

namespace App\Http\Controllers;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Filament\Applicant\Pages\Application;
use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\Term;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicGatewayController extends Controller
{
    public function __invoke(Request $request, ApplicantEntryReadinessService $entry, AdmissionWindowService $windows): View
    {
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

        $data['programDurations'] = $data['programs']->pluck('duration_years')->unique()->sort()->values();
        $duration = $request->integer('duration');
        $availability = $request->query('availability');
        $data['programFilters'] = array_filter([
            'duration' => $data['programDurations']->contains($duration) ? $duration : null,
            'availability' => in_array($availability, ['open', 'enquire'], true) ? $availability : null,
        ], fn (mixed $value): bool => $value !== null);
        $data['selectedProgram'] = $data['programs']->firstWhere('id', $request->integer('program'));
        $data['programs'] = $data['programs']->filter(function (Program $program) use ($data): bool {
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
        return Program::query()->where('is_active', true)
            ->whereHas('admissionCycles', fn (Builder $query): Builder => $query
                ->where('state', AdmissionCycle::StatePublished)
                ->where('opens_at', '<=', now())
                ->where('closes_at', '>', now())
                ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
                ->where(fn (Builder $eligible): Builder => $eligible
                    ->where('admission_cycle_program.accepts_first_year', true)
                    ->orWhere('admission_cycle_program.accepts_transferee', true)))
            ->get();
    }
}
