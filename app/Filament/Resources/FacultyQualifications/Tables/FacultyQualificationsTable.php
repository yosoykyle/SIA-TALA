<?php

namespace App\Filament\Resources\FacultyQualifications\Tables;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Component;

class FacultyQualificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'course.specifications' => fn (HasMany $revisions): HasMany => $revisions->where('state', CourseSpecification::StateActive)->latest('id')->limit(1),
                'faculty', 'recorder',
            ]))
            ->columns([
                TextColumn::make('faculty.name')
                    ->label('Teacher')
                    ->weight('medium')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('course.code')
                    ->label('Approved course')
                    ->formatStateUsing(fn (?string $state, FacultyQualification $record): string => $record->course?->specifications->first()?->title ?? $state ?? 'Course unavailable')
                    ->description(fn (FacultyQualification $record): ?string => $record->course?->code)
                    ->weight('medium')
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $qualifications): Builder => $qualifications
                        ->whereHas('course', fn (Builder $courses): Builder => $courses->where('code', 'like', '%'.$search.'%'))
                        ->orWhereHas('course.specifications', fn (Builder $revisions): Builder => $revisions->where('state', CourseSpecification::StateActive)->where('title', 'like', '%'.$search.'%'))))
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('recorded_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y')
                    ->description(fn (FacultyQualification $record): string => collect([$record->recorder?->name, $record->recorded_at?->timezone('Asia/Manila')->format('g:i A').' (Asia/Manila)'])->filter()->implode(' · '))
                    ->timezone('Asia/Manila')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('faculty_user_id')
                    ->label('Teacher')
                    ->relationship(
                        'faculty',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas('facultyQualifications'),
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('course_id')
                    ->label('Course')
                    ->relationship('course', 'code')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->searchPlaceholder('Search qualifications')
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter')->color('gray')->labeledFrom(null))
            ->filtersApplyAction(fn (Action $action): Action => $action->alpineClickHandler('$wire.applyTableFilters(); close()'))
            ->filtersRemoveAllAction(fn (Action $action): Action => $action->button()->label('Clear filters')->icon(null))
            ->recordActions([
                ViewAction::make()->label('View details')->button()->color('gray')->url(fn (FacultyQualification $record, Component $livewire): string => FacultyQualificationResource::getUrl('view', [
                    'record' => $record,
                    ...($livewire instanceof ListFacultyQualifications && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
                EditAction::make()->button()->color('gray')->url(fn (FacultyQualification $record, Component $livewire): string => FacultyQualificationResource::getUrl('edit', [
                    'record' => $record,
                    ...($livewire instanceof ListFacultyQualifications && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                ])),
            ])
            ->recordActionsColumnLabel('Actions')
            ->toolbarActions([])
            ->stackedOnMobile()
            ->defaultSort('recorded_at', 'desc');
    }
}
