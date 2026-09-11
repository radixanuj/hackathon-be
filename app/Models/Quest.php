<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quest extends Model
{
    protected $fillable = ['user_id', 'status', 'starts_at', 'due_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(QuestTarget::class);
    }

    /** Close the quest out once every suggested person has been met or skipped. */
    public function refreshCompletion(): void
    {
        $pending = $this->targets()->where('status', 'pending')->count();

        if ($pending === 0 && $this->status === 'active') {
            $this->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}
