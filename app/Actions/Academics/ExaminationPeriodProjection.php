<?php

namespace App\Actions\Academics;

use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;

class ExaminationPeriodProjection
{
    /** @return array<string, mixed> */
    public function forTerm(?Term $term): array
    {
        if (! $term instanceof Term) {
            return $this->unavailable('No exact Term is selected. Select a Term with an active Calendar Package.');
        }

        $package = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->with('windows')
            ->latest('version')
            ->first();

        if (! $package instanceof TermCalendarPackage) {
            return $this->unavailable(
                'No active exact-Term Calendar Package is available. Registrar must activate the approved package before dates can be shown.',
                $term,
            );
        }

        $window = $package->windows->firstWhere('window_type', TermCalendarWindow::TypeExaminationPeriod);

        if (! $window instanceof TermCalendarWindow || $window->closes_on->lt($window->opens_on)) {
            return $this->unavailable(
                'The active Calendar Package has no valid Examination Period. Registrar must correct the package; TALA will not infer dates.',
                $term,
                $package,
            );
        }

        return [
            'status' => 'Available',
            'term' => $term->label,
            'opens_on' => $window->opens_on,
            'closes_on' => $window->closes_on,
            'package_version' => $package->version,
            'authority_reference' => $package->authority_reference,
            'authority_date' => $package->authority_date,
            'owner' => 'Registrar',
            'as_of' => now('Asia/Manila'),
            'message' => 'Term-level information only. Class-level examination arrangements are not inferred.',
        ];
    }

    /** @return array<string, mixed> */
    public function latest(): array
    {
        $term = Term::query()
            ->whereHas('calendarPackages', fn ($query) => $query->where('state', TermCalendarPackage::StateActive))
            ->latest('starts_on')
            ->first();

        return $this->forTerm($term);
    }

    /** @return array<string, mixed> */
    private function unavailable(string $message, ?Term $term = null, ?TermCalendarPackage $package = null): array
    {
        return [
            'status' => 'Unavailable',
            'term' => $term?->label,
            'opens_on' => null,
            'closes_on' => null,
            'package_version' => $package?->version,
            'authority_reference' => $package?->authority_reference,
            'authority_date' => $package?->authority_date,
            'owner' => 'Registrar',
            'as_of' => now('Asia/Manila'),
            'message' => $message,
        ];
    }
}
