<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeHourBooking extends Model
{
    protected $fillable = ['office_hour_slot_id', 'user_id', 'topic', 'status'];

    protected $attributes = ['status' => 'booked'];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(OfficeHourSlot::class, 'office_hour_slot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
