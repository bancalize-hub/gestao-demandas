<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'hot' => 'boolean',
        'won' => 'boolean',
        'tag_strong' => 'boolean',
    ];
}
