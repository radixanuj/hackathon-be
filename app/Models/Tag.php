<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    public const TYPES = ['skill', 'interest'];

    protected $fillable = ['name', 'slug', 'type', 'usage_count'];

    protected $attributes = ['usage_count' => 0];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tag')->withPivot('kind')->withTimestamps();
    }

    /** Tags are user-generated, so reuse an existing one whenever the slug matches. */
    public static function findOrCreateByName(string $name, string $type = 'skill'): self
    {
        $slug = Str::slug($name);

        return static::firstOrCreate(
            ['slug' => $slug],
            ['name' => trim($name), 'type' => $type, 'usage_count' => 0],
        );
    }
}
