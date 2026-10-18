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
                'question' => 'How do I apply for admission to SIA?',
                'answer' => 'When admissions are open, select Apply on this page and create an applicant account with your email address. Verify your email, sign in, and complete the online application in five steps: program and applicant type, identity and contact details, education, copies of your requirements, and a final review before you submit. After you submit, the Registrar reviews your application. Your Home page shows your status, any corrections the Registrar asks for, and your next step.',
                'category' => FaqEntry::CategoryAdmissionEnrollment,
                'sort_order' => 1,
                'is_published' => true,
            ],
            [
                'system_key' => 'admission-requirements',
                'question' => 'What are the admission requirements?',
                'answer' => 'Requirements depend on whether you apply as a freshman or a transferee, and are set by the Registrar for each admission period. After you choose your program and applicant type, your application lists each requirement and how to submit it: upload a copy (PDF, JPEG, or PNG, up to 10 MB), bring it in person, or have your previous school send it. You also need your 12-digit Learner Reference Number (LRN). Your Requirements checklist shows what is still needed and when it is due.',
                'category' => FaqEntry::CategoryAdmissionEnrollment,
                'sort_order' => 2,
                'is_published' => true,
            ],
            [
                'system_key' => 'verify-account',
                'question' => 'How do I verify my account?',
                'answer' => 'After you create your applicant account, we send a verification link to your email address. Open the newest email and select the link within 60 minutes. If it does not arrive, check your spam folder, then sign in and select Resend verification link (you can request a new link once a minute). Only the newest link works, so earlier links stop working when you request another.',
                'category' => FaqEntry::CategoryAccountLogin,
                'sort_order' => 3,
                'is_published' => true,
            ],
            [
                'system_key' => 'campus-location',
                'question' => 'Where is SIA located?',
                'answer' => 'Find Servitech Institute Asia on the map in the Visit Servitech section of this page, and select Get directions for a route to campus. Contact the school before your visit if you need location guidance.',
                'category' => FaqEntry::CategoryGeneral,
                'sort_order' => 4,
                'is_published' => true,
            ],
        ];
    }
}
