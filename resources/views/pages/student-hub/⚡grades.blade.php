<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.student')]
new class extends Component
{
    //
};
?>

<div>
    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Academic Grades</h1>
    <p class="mb-6 mt-2 text-base text-zinc-500 dark:text-zinc-400">View your midterm and final grades.</p>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm p-6 text-center">
        <p class="text-zinc-500 dark:text-zinc-400">Grades will be displayed here.</p>
    </div>
</div>