<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Arquivo que a IA pode mandar para o lead (PDF de apresentação, tabela de taxas…).
 * `quando` é a instrução em linguagem natural que a IA lê para decidir se cabe agora.
 */
class Material extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'size' => 'integer',
    ];
}
