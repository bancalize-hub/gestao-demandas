<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lista (coluna) do quadro de tarefas. Cada empresa monta as suas. */
class TaskList extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
