<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RadixAnswer extends Model
{
    protected $fillable = ['radix_question_id', 'user_id', 'body', 'is_accepted'];

    protected $attributes = ['is_accepted' => false];

    protected function casts(): array
    {
        return ['is_accepted' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(RadixQuestion::class, 'radix_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
