<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

class ViewStudentLifecycleChange extends Page
{
    use HasAcademicOversightReturn;

    protected static string $resource = StudentLifecycleChangeResource::class;

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
