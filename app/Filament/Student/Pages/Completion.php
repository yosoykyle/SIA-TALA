<?php

namespace App\Filament\Student\Pages;

use App\Models\GraduationSnapshot;
use Filament\Pages\Page;

class Completion extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Completion';

    protected static ?string $title = 'Completion Review';

    protected string $view = 'filament.student.pages.completion';

    public ?GraduationSnapshot $snapshot = null;

    /** @var array<string, mixed>|null */
    public ?array $projection = null;

    public function mount(): void
    {
        $studentProfileId = auth()->user()?->studentProfile?->id;

        $this->snapshot = GraduationSnapshot::query()
            ->with('member.studentProfile')
            ->whereNotNull('made_visible_at')
            ->whereHas('member', fn ($query) => $query->where('student_profile_id', $studentProfileId ?? 0))
            ->latest('version')
            ->latest('generated_at')
            ->first();

        $this->projection = $this->snapshot?->evaluation_snapshot['student_projection'] ?? null;
    }
}
