<?php

namespace App\Enums;

/**
 * TAL-92E: retention category classification.
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.7.1–13.7.3
 * (Permanent/Long-Term, Archive-After-Review, and Shorter-Operational record
 * groups) and §13.7.4 rule 6 (exact retention periods are institution-
 * configured policy values, not hardcoded here). This enum only classifies
 * the *category* a record type belongs to; the reference mapping of record
 * types to categories lives in `config/tala_retention.php`.
 */
enum RetentionCategory: string
{
    case Permanent = 'permanent';
    case ArchiveAfterReview = 'archive_after_review';
    case ShortOperational = 'short_operational';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent / Long-Term',
            self::ArchiveAfterReview => 'Archive After Review',
            self::ShortOperational => 'Short Operational',
        };
    }
}
