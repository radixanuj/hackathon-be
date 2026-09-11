<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ama extends Model
{
    protected $table = 'amas';

    public const FORMATS = ['async', 'live'];

    protected $fillable = [
        'host_id', 'title', 'description', 'format', 'status', 'opens_at', 'closes_at',
        'scheduled_at', 'location', 'story_id', 'questions_count',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    protected $attributes = ['questions_count' => 0];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(AmaQuestion::class);
    }

    public function syncQuestionsCount(): void
    {
        $this->update(['questions_count' => $this->questions()->count()]);
    }
}
