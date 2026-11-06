<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

class ViewStudentLifecycleChange extends Page
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function mount(int|string $record): void
    {
        $change = StudentLifecycleChangeResource::getEloquentQuery()->whereKey($record)->firstOrFail();
        Gate::authorize('view', $change);

        $this->redirect(StudentLifecycleChangeResource::getUrl('index', [
            'change' => $change->id,
            'oversightTermId' => $this->oversightTermId,
        ]));
    }
}
