<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class PreparePayMongoDemo extends Command
{
    protected $signature = 'acceptance:prepare-paymongo-demo';

    protected $description = 'Retired historical PayMongo demonstration fixture.';

    public function handle(): int
    {
        $this->error('This historical FeeRule-based PayMongo demo fixture is retired. Use the canonical manual evidence journey; provider activation belongs to Slice 6.');

        return self::FAILURE;
    }
}
