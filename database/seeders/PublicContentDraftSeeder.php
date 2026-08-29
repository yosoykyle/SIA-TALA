<?php

namespace Database\Seeders;

use App\Models\PublicNotice;
use Illuminate\Database\Seeder;
use LogicException;

class PublicContentDraftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic notice drafts are limited to local and testing environments.');
        }

        PublicNotice::query()->firstOrCreate(
            ['title' => 'Synthetic notice draft — acceptance only'],
            ['message' => 'Synthetic fixture for draft, preview, and publication checks. This is not institutional guidance.',
                'display_order' => ((int) PublicNotice::query()->max('display_order')) + 1],
        );
    }
}
