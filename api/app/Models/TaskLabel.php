<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Etiqueta colorida do quadro (a cor é o que se lê no cartão; o nome é opcional). */
class TaskLabel extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_label_task');
    }
}
