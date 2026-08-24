<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAccessProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'staff_identifier',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
