<?php

namespace App\Http\Controllers;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Program;
use App\Models\PublicNotice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\QueryException;

class PublicGatewayController extends Controller
{
    public function __invoke(ApplicantEntryReadinessService $entry, AdmissionWindowService $windows): View
    {
        $data = [
            'officialReferences' => $entry->officialReferences(),
            'admissionsOpen' => false,
            'applicantEntryReady' => false,
            'admissionCycle' => null,
            'admissionState' => 'Missing',
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
            $data['acceptingProgramIds'] = $cycle?->programs
                ->filter(function (Program $program): bool {
                    $eligibility = $program->getRelation('pivot');

                    return $program->is_active && $eligibility instanceof Pivot
                        && ($eligibility->getAttribute('accepts_first_year') || $eligibility->getAttribute('accepts_transferee'));
                })
                ->modelKeys() ?? [];
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

        return view('welcome', $data);
    }
}
