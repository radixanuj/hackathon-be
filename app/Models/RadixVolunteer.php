<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadixVolunteer extends Model
{
    protected $fillable = ['radix_question_id', 'user_id', 'note'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(RadixQuestion::class, 'radix_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
