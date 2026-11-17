<?php

namespace Tests\Feature\Finance;

use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LifecycleAccountingReviewConcurrencyTest extends TestCase
{
    public function test_competing_results_serialize_and_the_stale_result_is_rejected(): void
    {
        $database = (string) env('TALA_TEST_DATABASE', 'test_tala_db');
        $this->assertMatchesRegularExpression('/\Atest_tala_db(?:_[a-z0-9_]+)?\z/', $database);
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame($database, config('database.connections.mysql.database'));
        $this->assertSame($database, DB::connection()->getDatabaseName());
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS name')->name);
        config(['database.connections.lifecycle_review_monitor' => config('database.connections.mysql')]);
        $monitor = DB::connection('lifecycle_review_monitor');
        $this->assertSame($database, $monitor->getDatabaseName());
        $this->assertSame($database, $monitor->selectOne('SELECT DATABASE() AS name')->name);

        $fixture = DB::transaction(function (): array {
            $role = Role::query()->where('name', User::StaffRoleAccounting)->where('guard_name', 'web')->first();
            $createdRole = $role === null;
            $role ??= Role::query()->create(['name' => User::StaffRoleAccounting, 'guard_name' => 'web']);
            $actor = User::factory()->create(['status' => User::StatusActive]);
            $actor->assignRole($role);
            $enrollment = Enrollment::factory()->create();
            $account = TermAccount::factory()->create([
                'enrollment_id' => $enrollment->id,
                'credential_user_id' => $enrollment->credential_user_id,
                'term_id' => $enrollment->term_id,
            ]);
            $source = StudentLifecycleChange::factory()->create([
                'student_profile_id' => $enrollment->student_profile_id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'state' => StudentLifecycleChange::StateApplied,
            ]);
            $event = app(LifecycleFinanceAction::class)->execute($source, $actor);
            $student = $enrollment->studentProfile;
            $term = $enrollment->term;

            return compact('role', 'createdRole', 'actor', 'enrollment', 'account', 'source', 'event', 'student', 'term');
        });

        $worker = null;
        try {
            $reviewed = app(LifecycleAccountingReview::class)->snapshot($fixture['enrollment'], $fixture['event']->id);
            DB::beginTransaction();
            Enrollment::query()->whereKey($fixture['enrollment']->id)->lockForUpdate()->firstOrFail();
            $worker = new Process([
                PHP_BINARY,
                base_path('tests/Feature/Concurrency/lifecycle_review_worker.php'),
                base64_encode(json_encode(['enrollment_id' => $fixture['enrollment']->id, 'actor_id' => $fixture['actor']->id, 'reviewed' => $reviewed], JSON_THROW_ON_ERROR)),
            ], base_path(), ['TALA_TEST_DATABASE' => $database, 'DB_DATABASE' => $database, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql']);
            $worker->setTimeout(30)->start();

            $threadId = null;
            for ($attempt = 0; $attempt < 200; $attempt++) {
                if (preg_match('/READY:(\d+)/', $worker->getOutput(), $match) === 1) {
                    $threadId = (int) $match[1];
                    break;
                }
                if (! $worker->isRunning()) {
                    break;
                }
                usleep(50000);
            }
            $this->assertNotNull($threadId, $worker->getOutput().$worker->getErrorOutput());
            $waiting = false;
            $observed = 0;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $processState = $monitor->selectOne('SELECT INFO FROM information_schema.processlist WHERE ID = ?', [$threadId]);
                $query = strtolower((string) $processState?->INFO);
                $observed = str_contains($query, 'from `enrollments`') && str_contains($query, 'for update') ? $observed + 1 : 0;
                if ($observed >= 3) {
                    $waiting = true;
                    break;
                }
                if (! $worker->isRunning()) {
                    break;
                }
                usleep(50000);
            }
            $this->assertTrue($waiting, 'The competing confirmation must wait on the held enrollment lock. '.$worker->getOutput().$worker->getErrorOutput());

            $result = app(RecordLifecycleAccountingReview::class)->execute($fixture['enrollment'], $fixture['actor'], $reviewed, [
                'outcome' => LifecycleAccountingReview::NoAccountChange,
                'reason' => 'First completed review.',
            ]);
            DB::commit();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString('REJECTED:review', $worker->getOutput());
            $results = $fixture['event']->lifecycleReviewResults()->get();
            $this->assertCount(1, $results);
            $this->assertSame($result->id, $results->sole()->id);
            $this->assertSame('First completed review.', $results->sole()->payload['reason']);
        } finally {
            if ($worker?->isRunning()) {
                $worker->stop();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::transaction(function () use ($fixture): void {
                OperationalEvent::query()->where('related_record_type', OperationalEvent::class)->where('related_record_id', $fixture['event']->id)->delete();
                $fixture['event']->delete();
                $fixture['source']->delete();
                $fixture['account']->delete();
                $fixture['enrollment']->delete();
                $fixture['student']->delete();
                DB::table('curriculum_versions')->where('id', $fixture['student']->curriculum_version_id)->delete();
                DB::table('programs')->where('id', $fixture['student']->program_id)->delete();
                $fixture['term']->delete();
                DB::table('academic_years')->where('id', $fixture['term']->academic_year_id)->delete();
                DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $fixture['actor']->id)->delete();
                User::query()->whereIn('id', [$fixture['actor']->id, $fixture['student']->user_id])->delete();
                if ($fixture['createdRole']) {
                    $fixture['role']->delete();
                }
            });
            DB::purge('lifecycle_review_monitor');
        }
    }
}
