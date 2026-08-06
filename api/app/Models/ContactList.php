<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Um grupo de contatos (Google, planilha importada, leads do CRM, manual).
 * É o que a campanha seleciona para saber para quem disparar.
 *
 * Lista com `auto` guarda em `criteria` a regra que a define (etapa do funil, só
 * anúncio, …) e é reaplicada pelo `lists:sync` — assim ela cresce sozinha com os
 * leads novos, em vez de ser a foto do dia em que foi criada.
 */
class ContactList extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'auto' => 'boolean',
        'criteria' => 'array',
        'synced_at' => 'datetime',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_list_contact');
    }
}
