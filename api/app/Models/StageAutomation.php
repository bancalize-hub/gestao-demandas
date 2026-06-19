<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageAutomation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(StageAutomationStep::class, 'automation_id')->orderBy('position')->orderBy('id');
    }
}
