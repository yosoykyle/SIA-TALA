<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\DocumentEvidence;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Issue57DiscardSubmissionConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_submission_wins_and_competing_discard_preserves_submitted_evidence(): void
    {
        $this->race(submitWins: true);
    }

    public function test_discard_wins_and_competing_submission_cannot_recreate_deleted_draft(): void
    {
        $this->race(submitWins: false);
    }

    private function race(bool $submitWins): void
    {
        $original = DB::getDefaultConnection();
        $this->assertSame('test_tala_db', config("database.connections.{$original}.database"));
        $this->assertSame('test_tala_db', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        DB::select('SELECT trx_mysql_thread_id FROM information_schema.innodb_trx LIMIT 1');
        config(['database.connections.issue57_race' => config("database.connections.{$original}")]);
        DB::setDefaultConnection('issue57_race');
        $connection = DB::connection();
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $this->assertSame('test_tala_db', $connection->selectOne('SELECT DATABASE() AS db')->db);
        $application = $user = $cycle = $set = $requirement = null;
        $path = $workerPath = null;
        $worker = null;
        Mail::fake();

        try {
            $this->assertNotNull(Term::query()->value('id'), 'Retained test schema needs a Term.');
            $this->assertNotNull(Program::query()->value('id'), 'Retained test schema needs a Program.');
            $connection->beginTransaction();
            $user = User::factory()->create(['status' => User::StatusActive]);
            $user->assignRole('applicant');
            $cycle = AdmissionCycle::factory()->published()->create([
                'term_id' => Term::query()->value('id'), 'registrar_owner_id' => $user->id,
            ]);
            $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $cycle->id]);
            $requirement = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id]);
            $set->update(['state' => AdmissionRequirementSet::StatePublished, 'effective_at' => now()->subMinute(), 'published_at' => now()]);
            $application = AdmissionApplication::factory()->create([
                'user_id' => $user->id, 'admission_cycle_id' => $cycle->id,
                'program_id' => Program::query()->value('id'), 'accuracy_declared_at' => now(),
                'first_name' => 'Race'.str()->ulid(), 'privacy_notice_reference' => $cycle->privacy_notice_reference,
            ]);
            $path = "admission-applications/{$application->id}/requirements/{$requirement->id}/race.txt";
            Storage::disk('local')->put($path, 'Synthetic isolated race evidence');
            DocumentEvidence::factory()->create([
                'checklist_item_id' => null, 'admission_application_id' => $application->id,
                'admission_requirement_id' => $requirement->id, 'application_submission_version_id' => null,
                'uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path,
            ]);
            $connection->commit();
            $workerPath = tempnam(sys_get_temp_dir(), 'tala57-race-');
            file_put_contents($workerPath, $this->workerSource());
            $connection->beginTransaction();
            AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $worker = new Process([PHP_BINARY, $workerPath, base_path(), (string) $application->id, $submitWins ? 'discard' : 'submit'], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => $original, 'DB_DATABASE' => 'test_tala_db',
            ]);
            $worker->setTimeout(20)->start();
            $ready = false;
            for ($attempt = 0; $attempt < 200; $attempt++) {
                if (str_contains($worker->getOutput(), 'READY:')) {
                    $ready = true;
                    break;
                }
                if (! $worker->isRunning()) {
                    break;
                }
                usleep(50000);
            }
            $this->assertTrue($ready, $worker->getOutput().$worker->getErrorOutput());
            preg_match('/READY:(\d+)/', $worker->getOutput(), $thread);
            $waiting = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $transaction = $connection->selectOne('SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?', [(int) $thread[1]]);
                if ($transaction?->trx_state === 'LOCK WAIT') {
                    $waiting = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($waiting, 'The real competing action must wait on the held application lock.');
            if ($submitWins) {
                app(SubmitAdmissionApplication::class)->execute($application, $user, $set->id);
            } else {
                app(DiscardAdmissionApplication::class)->execute($application, $user);
            }
            $connection->commit();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertStringContainsString($submitWins ? 'REJECTED:validation' : 'REJECTED:missing', $worker->getOutput());
            $this->assertSame($submitWins ? AdmissionApplication::StateSubmitted : null, AdmissionApplication::query()->find($application->id)?->application_state);
            $this->assertSame($submitWins ? 1 : 0, DocumentEvidence::query()->where('admission_application_id', $application->id)->count());
            $this->assertSame($submitWins ? 1 : 0, $connection->table('application_submission_versions')->where('admission_application_id', $application->id)->count());
            $this->assertSame($submitWins, Storage::disk('local')->exists($path));
        } finally {
            if ($worker?->isRunning()) {
                $worker->stop();
            }
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->transaction(function () use ($connection, $application, $user, $cycle, $set, $requirement): void {
                if ($application !== null) {
                    $connection->table('operational_events')->where('related_record_type', AdmissionApplication::class)->where('related_record_id', $application->id)->delete();
                    foreach (['admission_application_events', 'identity_match_reviews', 'document_evidence'] as $table) {
                        $connection->table($table)->where('admission_application_id', $application->id)->delete();
                    }
                    $connection->table('applicant_intakes')->where('id', $application->id)->update(['current_submission_version_id' => null]);
                    $connection->table('application_submission_versions')->where('admission_application_id', $application->id)->delete();
                    $connection->table('applicant_intakes')->where('id', $application->id)->delete();
                }
                if ($requirement !== null) {
                    $connection->table('admission_requirements')->where('id', $requirement->id)->delete();
                }
                if ($set !== null) {
                    $connection->table('admission_requirement_sets')->where('id', $set->id)->delete();
                }
                if ($cycle !== null) {
                    $connection->table('admission_cycles')->where('id', $cycle->id)->delete();
                }
                if ($user !== null) {
                    $connection->table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->delete();
                    $connection->table('users')->where('id', $user->id)->delete();
                }
            });
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            if ($workerPath !== null) {
                unlink($workerPath);
            }
            DB::setDefaultConnection($original);
            DB::purge('issue57_race');
        }
    }

    private function workerSource(): string
    {
        return <<<'PHP'
        <?php
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $configured = config('database.connections.'.config('database.default').'.database');
        $active = Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db;
        if ($configured !== 'test_tala_db' || $active !== 'test_tala_db' || ! app()->environment('testing')) {
            throw new RuntimeException('Unsafe race worker database');
        }
        Illuminate\Support\Facades\Mail::fake();
        $application = App\Models\AdmissionApplication::query()->findOrFail((int) $argv[2]);
        $user = $application->user;
        echo 'READY:'.Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id.PHP_EOL;
        flush();
        try {
            $action = $argv[3] === 'submit' ? App\Actions\Admissions\SubmitAdmissionApplication::class : App\Actions\Admissions\DiscardAdmissionApplication::class;
            app($action)->execute($application, $user);
            echo 'UNEXPECTED:completed'.PHP_EOL;
            exit(1);
        } catch (Illuminate\Validation\ValidationException) {
            echo 'REJECTED:validation'.PHP_EOL;
        } catch (Illuminate\Database\Eloquent\ModelNotFoundException) {
            echo 'REJECTED:missing'.PHP_EOL;
        }
        PHP;
    }
}
