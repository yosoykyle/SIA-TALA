<?php

namespace Database\Seeders;

use App\Models\FaqEntry;
use Illuminate\Database\Seeder;
use Spatie\Activitylog\ActivityLogStatus;

class FaqEntrySeeder extends Seeder
{
    /**
     * Seed the baseline public landing FAQ entries.
     *
     * Records are matched on a stable {@see FaqEntry::$system_key} and only
     * created when missing, so re-running never overwrites later admin edits.
     * Baseline seeding is system-owned content with no causer, so activity
     * logging is suppressed to keep a freshly seeded database audit-clean.
     */
    public function run(): void
    {
        $logStatus = app(ActivityLogStatus::class);
        $wasDisabled = $logStatus->disabled();
        $logStatus->disable();

        try {
            foreach ($this->baselineEntries() as $entry) {
                FaqEntry::query()->firstOrCreate(
                    ['system_key' => $entry['system_key']],
                    $entry,
                );
            }
        } finally {
            if (! $wasDisabled) {
                $logStatus->enable();
            }
        }
    }

    /**
     * The current landing questions, published as the baseline FAQ content.
     *
     * @return list<array{system_key: string, question: string, answer: string, category: string, sort_order: int, is_published: bool}>
     */
    private function baselineEntries(): array
    {
        return [
            [
                'system_key' => 'apply-admission',
                'question' => 'How do I apply for admission?',
                'answer' => 'Use Apply Online to create an applicant account. The Applicant Workspace guides draft application, checklist, and allowed evidence steps.',
                'category' => FaqEntry::CategoryAdmissionEnrollment,
                'sort_order' => 1,
                'is_published' => true,
            ],
            [
                'system_key' => 'workspaces-access',
                'question' => 'What workspaces can I access?',
                'answer' => 'Applicants use the Applicant Workspace before handover. Students use Student Hub after official activation. Staff users use the Staff Workspace according to assigned role and authorization.',
                'category' => FaqEntry::CategoryGeneral,
                'sort_order' => 2,
                'is_published' => true,
            ],
            [
                'system_key' => 'who-can-register',
                'question' => 'Can students or staff register here?',
                'answer' => 'No. Public self-registration is only for applicants. Student and staff accounts are activated through official school processes.',
                'category' => FaqEntry::CategoryAccountLogin,
                'sort_order' => 3,
                'is_published' => true,
            ],
        ];
    }
}
