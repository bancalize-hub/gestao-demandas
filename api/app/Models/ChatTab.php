<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatTab extends Model
{
    protected $guarded = [];

    protected $casts = ['stages' => 'array'];
}
