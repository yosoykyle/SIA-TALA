<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Actions\Calendar\TermTeachingGrid;
use App\Models\Term;
use App\Support\TeachingWeekSummary;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class TermInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            View::make('filament.admin.terms.settings')
                ->viewData(function (Term $record): array {
                    $activeCalendar = app(TermTeachingGrid::class)->activePackage($record);

                    return [
                        'term' => $record->loadMissing('academicYear'),
                        'activeCalendar' => $activeCalendar,
                        'teachingWeekRows' => $activeCalendar ? app(TeachingWeekSummary::class)->rows($activeCalendar) : [],
                    ];
                })->columnSpanFull(),
            Section::make('Historical settings')
                ->description('Earlier grid values. Current scheduling uses the active teaching calendar.')
                ->collapsible()->collapsed()->columnSpanFull()->schema([
                    View::make('filament.admin.terms.history')
                        ->viewData(fn (Term $record): array => ['historyTerm' => $record]),
                ]),
            Section::make('Calendar history')->collapsible()->collapsed()->columnSpanFull()->schema([
                View::make('filament.admin.terms.history')
                    ->viewData(fn (Term $record): array => [
                        'calendarVersions' => $record->calendarPackages()->with('recorder')->latest('version')->get(),
                    ]),
            ]),
        ]);
    }
}
