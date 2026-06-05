<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.student')] class extends Component
{
    //
};
?>

<div>
    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Document Requests</h1>
    <p class="mb-6 mt-2 text-base text-zinc-500 dark:text-zinc-400">Request official school documents and track fulfillment.</p>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm p-6 text-center">
        <p class="text-zinc-500 dark:text-zinc-400">Document request portal will be displayed here.</p>
    </div>
</div>