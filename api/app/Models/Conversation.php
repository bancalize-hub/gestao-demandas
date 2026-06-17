<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'online' => 'boolean',
        'hot' => 'boolean',
        'prob' => 'integer',
        'unread' => 'integer',
        'archived' => 'boolean',
        'tags' => 'array',
        'interactions' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('position');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
