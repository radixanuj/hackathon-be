<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeHourSlot extends Model
{
    protected $fillable = [
        'host_id', 'title', 'description', 'starts_at', 'duration_minutes',
        'capacity', 'location', 'link', 'status', 'bookings_count',
    ];

    protected $attributes = ['status' => 'open', 'bookings_count' => 0];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(OfficeHourBooking::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->where('status', 'open');
    }

    /** Cancelled bookings free the seat back up. */
    public function syncBookingsCount(): void
    {
        $this->update(['bookings_count' => $this->bookings()->where('status', 'booked')->count()]);
    }

    public function isFull(): bool
    {
        return $this->bookings_count >= $this->capacity;
    }
}
