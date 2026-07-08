<?php

namespace App\Filament\Resources\DisposalReviews;

use App\Filament\Resources\DisposalReviews\Pages\ListDisposalReviews;
use App\Filament\Resources\DisposalReviews\Pages\ViewDisposalReview;
use App\Filament\Resources\DisposalReviews\Schemas\DisposalReviewInfolist;
use App\Filament\Resources\DisposalReviews\Tables\DisposalReviewsTable;
use App\Models\StudentProfile;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * TAL-92E: read-only disposal-candidate table + confirmation action.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7.4/§13.7.5
 * and §13.8 "Retention/disposal review" row ("Read-only candidate table
 * plus explicit, permission-controlled confirmation that records are not
 * under hold"). Direction A (confirmed 2026-07-08): disposal-review is a
 * ledger; it never physically deletes/purges any record.
 *
 * The underlying model is `StudentProfile` (the candidate source), not
 * `DisposalReview` (the audit-trail record written by the row action) —
 * mirrors the PRD's framing of this surface as a "candidate table". Only
 * Short-Operational-category candidates are in scope for this slice
 * (starting with rejected duplicate profiles, i.e. `StudentProfile` rows
 * with `archived_at` set); Permanent and Archive-After-Review record types
 * are excluded per the TAL-92E handoff packet.
 */
class DisposalReviewResource extends Resource
{
    protected static ?string $model = StudentProfile::class;

    protected static ?string $slug = 'disposal-reviews';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static string|UnitEnum|null $navigationGroup = 'System Administration';

    protected static ?string $navigationLabel = 'Disposal Review';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Disposal Candidate';

    protected static ?string $pluralModelLabel = 'Disposal Candidates';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('archived_at');
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisposalReviewInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisposalReviewsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisposalReviews::route('/'),
            'view' => ViewDisposalReview::route('/{record}'),
        ];
    }
}
