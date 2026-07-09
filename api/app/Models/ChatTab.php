<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class ChatTab extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['stages' => 'array'];
}
