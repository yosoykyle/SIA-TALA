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
    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Welcome back, Student!</h1>
    <p class="mb-6 mt-2 text-base text-zinc-500 dark:text-zinc-400">Here is your academic overview for the 1st Semester 2026-2027.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Status Card -->
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Enrollment Status</h2>
                <x-badge text="Officially Enrolled" color="green" />
            </div>
            <p class="text-zinc-500 dark:text-zinc-400">Level: <span class="font-medium text-zinc-900 dark:text-white">College</span></p>
            <p class="text-zinc-500 dark:text-zinc-400">Program: <span class="font-medium text-zinc-900 dark:text-white">BS in Information Technology</span></p>
            <p class="text-zinc-500 dark:text-zinc-400">Year: <span class="font-medium text-zinc-900 dark:text-white">3rd Year</span></p>
        </div>

        <!-- Financial Card -->
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Financial Standing</h2>
            <div class="flex items-baseline gap-2 mb-2">
                <span class="text-3xl font-bold text-zinc-900 dark:text-white">₱0.00</span>
                <p class="text-zinc-500 dark:text-zinc-400">Current Balance</p>
            </div>
            <x-badge text="Fully Paid" color="gray" outline />
        </div>

        <!-- Next Class Card -->
        <div class="bg-white dark:bg-zinc-900 p-6 rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Next Class</h2>
            <div class="flex items-center gap-3 mb-2">
                <div class="p-2 bg-zinc-100 dark:bg-zinc-800 rounded-lg">
                    <x-icon name="clock" class="size-5 text-zinc-500" />
                </div>
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">Advanced Web Dev</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">1:00 PM - 3:00 PM | Room 302</p>
                </div>
            </div>
            <x-button text="View Full Schedule" color="primary" sm class="mt-2" />
        </div>
    </div>

    <div class="mt-8">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white mb-4">Quick Actions</h2>
        <div class="flex flex-wrap gap-4">
            <x-button text="Request Documents" icon="document-text" href="/student/documents" wire:navigate />
            <x-button text="View Report Card" icon="academic-cap" href="/student/grades" wire:navigate />
            <x-button text="Make a Payment" icon="credit-card" href="/student/financials" wire:navigate />
        </div>
    </div>
</div>