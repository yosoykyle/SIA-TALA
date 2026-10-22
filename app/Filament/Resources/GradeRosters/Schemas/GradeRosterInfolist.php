<?php

namespace App\Filament\Resources\GradeRosters\Schemas;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Models\GradeRoster;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;

class GradeRosterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)) {
            return $schema->components([
                SchemaView::make('filament.admin.grade-rosters.head-membership')->columnSpanFull()
                    ->viewData(function (GradeRoster $record): array {
                        $membership = app(CurrentClassRosterOutput::class)->membershipSnapshot($record, auth()->user());
                        $membership['roster']->loadMissing('teachingAssignment.faculty');

                        return $membership;
                    }),
            ]);
        }

        return $schema->components([
            Section::make('Roster')
                ->schema([
                    TextEntry::make('termOffering.term.label')->label('Term'),
                    TextEntry::make('termOffering.curriculumEntry.courseSpecification.course.code')->label('Course Code'),
                    TextEntry::make('termOffering.curriculumEntry.courseSpecification.title')->label('Course Title'),
                    TextEntry::make('section.code')->label('Section'),
                    TextEntry::make('teachingAssignment.faculty.name')->label('Designated Faculty'),
                    TextEntry::make('teachingAssignment.authority_reference')->label('Assignment Authority'),
                    TextEntry::make('current_version_number')->label('Current Version'),
                    TextEntry::make('membership_signature')->label('Membership Version')->copyable(),
                    TextEntry::make('state')->label('Roster Status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                    TextEntry::make('rows_count')->label('Students')->state(fn ($record): int => $record->rows()->where('is_current_membership', true)->count()),
                    TextEntry::make('submitted_at')->dateTime(),
                    TextEntry::make('released_at')->dateTime(),
                    TextEntry::make('return_reason')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
