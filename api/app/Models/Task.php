<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'due_at' => 'datetime',
        'archived_at' => 'datetime',
        'ai_draft_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function labels(): BelongsToMany
    {
        // Tabela declarada à mão: pela convenção do Laravel o nome seria
        // `task_task_label`, que não se lê. A ponte aqui é `task_label_task`.
        return $this->belongsToMany(TaskLabel::class, 'task_label_task');
    }

    /** Responsáveis pelo cartão (pessoas do time). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderByDesc('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderByDesc('id');
    }

    /**
     * O que aparece no quadro: só o que alguém pôs à mão — tarefa criada no painel ou
     * solicitação que o cliente mandou pelo formulário. Tudo que os robôs geram sozinhos
     * (negócio parado, proposta pós-reunião, remarcar no-show) é type='followup' e vive
     * na ficha do lead, dentro do chat, que é onde cada uma se resolve.
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'followup'));
    }

    public function scopeNoQuadro(Builder $query): Builder
    {
        return $query->manual()->whereNull('archived_at');
    }
}
