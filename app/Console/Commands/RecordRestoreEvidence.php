<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\OperationalEvidenceRecorder;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RecordRestoreEvidence extends Command
{
    protected $signature = 'tala:operations:record-restore-evidence
        {--input=- : Read one versioned JSON object from standard input or a local file}';

    protected $description = 'Record validated evidence from an approved isolated restore without running a restore.';

    public function handle(OperationalEvidenceRecorder $recorder): int
    {
        try {
            $result = $recorder->record(
                OperationalEvidenceRecorder::TypeRestore,
                $this->readInput(),
            );
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'Restore evidence was rejected.');

            return self::FAILURE;
        }

        $this->components->info($result['created']
            ? 'Restore evidence recorded.'
            : 'Identical restore evidence was already recorded.');

        return self::SUCCESS;
    }

    private function readInput(): string
    {
        $source = (string) $this->option('input');
        $contents = $source === '-'
            ? file_get_contents('php://stdin')
            : (is_file($source) ? file_get_contents($source) : false);

        if (! is_string($contents)) {
            throw ValidationException::withMessages(['input' => 'Evidence input could not be read.']);
        }

        return $contents;
    }
}
