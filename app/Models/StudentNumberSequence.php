<?php

namespace App\Models;

use Database\Factories\StudentNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentNumberSequence extends Model
{
    /** @use HasFactory<StudentNumberSequenceFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $primaryKey = 'year';

    protected $keyType = 'int';

    protected $fillable = ['year', 'last_number'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'last_number' => 'integer'];
    }
}
