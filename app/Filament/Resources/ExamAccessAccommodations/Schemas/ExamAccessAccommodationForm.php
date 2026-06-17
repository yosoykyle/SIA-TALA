<?php

namespace App\Filament\Resources\ExamAccessAccommodations\Schemas;

use App\Models\ExamAccessAccommodation;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExamAccessAccommodationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->label('Student')
                    ->relationship('studentProfile', 'student_id')
                    ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => PromissoryNote::studentOptionLabel($record))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('term_id', null);
                        $set('enrollment_id', null);
                        $set('promissory_note_id', null);
                    })
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'term_name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('enrollment_id', null);
                    })
                    ->required(),
                Select::make('enrollment_id')
                    ->label('Enrollment')
                    ->options(fn (Get $get): array => PromissoryNote::enrollmentOptionsFor(
                        $get('student_profile_id'),
                        $get('term_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('promissory_note_id')
                    ->label('Linked Promissory Note')
                    ->options(fn (Get $get): array => PromissoryNote::query()
                        ->where('student_profile_id', $get('student_profile_id'))
                        ->when($get('enrollment_id'), fn ($query, $enrollmentId) => $query->where('enrollment_id', $enrollmentId))
                        ->whereIn('status', [PromissoryNote::StatusPending, PromissoryNote::StatusApproved, 'active'])
                        ->latest('id')
                        ->get()
                        ->mapWithKeys(fn (PromissoryNote $note): array => [
                            $note->id => "#{$note->id} {$note->status} - PHP {$note->amount} due {$note->due_date?->toDateString()}",
                        ])
                        ->all())
                    ->searchable(),
                Select::make('basis')
                    ->options(ExamAccessAccommodation::basisOptions())
                    ->live()
                    ->required(),
                Textarea::make('request_reason')
                    ->rows(3)
                    ->maxLength(2000)
                    ->visible(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisInstitutionalDiscretion)
                    ->required(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisInstitutionalDiscretion)
                    ->columnSpanFull(),
                TextInput::make('certifying_office')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisRa11984Certification)
                    ->required(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisRa11984Certification),
                TextInput::make('certification_reference')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisRa11984Certification),
                DatePicker::make('certified_at')
                    ->visible(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisRa11984Certification),
                Hidden::make('evidence_disk')
                    ->default('local'),
                FileUpload::make('evidence_path')
                    ->label('Private certification evidence')
                    ->disk('local')
                    ->directory('exam-access-accommodations/private')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(5120)
                    ->visible(fn (Get $get): bool => $get('basis') === ExamAccessAccommodation::BasisRa11984Certification)
                    ->helperText('Stored privately; not exposed to Student, Faculty, public verification, or exam decision responses.'),
                DatePicker::make('valid_from'),
                DatePicker::make('valid_until'),
            ]);
    }
}
