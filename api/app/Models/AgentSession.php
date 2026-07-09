<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class)->orderBy('id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AgentJob::class)->orderBy('id');
    }
}
