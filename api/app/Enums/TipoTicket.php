<?php

namespace App\Enums;

enum TipoTicket: string
{
    case BUG = 'BUG';
    case FEATURE = 'FEATURE';

    public function label(): string
    {
        return match ($this) {
            self::BUG => 'Correção de Erro',
            self::FEATURE => 'Nova Funcionalidade',
        };
    }
}
