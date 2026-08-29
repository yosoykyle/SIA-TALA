<?php

namespace App\Models;

use App\Models\Concerns\HasPublicContentVersions;
use Database\Factories\PublicNoticeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $title
 * @property string $message
 * @property string $state
 * @property int $display_order
 * @property string|null $link_label
 * @property string|null $link_url
 * @property int|null $root_id
 * @property int|null $previous_version_id
 * @property int $version
 * @property int $revision
 * @property bool $ever_published
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $published_by
 * @property Carbon|null $visible_from
 * @property Carbon|null $visible_until
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PublicNotice extends Model
{
    /** @use HasFactory<PublicNoticeFactory> */
    use HasFactory, HasPublicContentVersions, LogsActivity;

    protected $fillable = ['title', 'message', 'display_order', 'link_label', 'link_url', 'visible_from', 'visible_until'];

    protected $attributes = ['state' => 'Draft', 'version' => 1, 'revision' => 1, 'ever_published' => false];

    protected function casts(): array
    {
        return ['visible_from' => 'datetime', 'visible_until' => 'datetime', 'published_at' => 'datetime', 'ever_published' => 'boolean', 'version' => 'integer', 'revision' => 'integer', 'display_order' => 'integer'];
    }

    public static function publicationColumn(): string
    {
        return 'state';
    }

    public static function contentFields(): array
    {
        return ['title', 'message', 'display_order', 'link_label', 'link_url', 'visible_from', 'visible_until'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('public_content')->logAll()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
