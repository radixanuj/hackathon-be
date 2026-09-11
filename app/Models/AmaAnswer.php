<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmaAnswer extends Model
{
    protected $fillable = ['ama_question_id', 'user_id', 'body'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(AmaQuestion::class, 'ama_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
