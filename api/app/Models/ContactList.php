<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Um grupo de contatos (Google, planilha importada, leads do CRM, manual).
 * É o que a campanha seleciona para saber para quem disparar.
 */
class ContactList extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_list_contact');
    }
}
