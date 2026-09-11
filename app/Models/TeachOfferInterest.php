<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachOfferInterest extends Model
{
    protected $fillable = ['teach_offer_id', 'user_id'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(TeachOffer::class, 'teach_offer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
