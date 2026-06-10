<?php

namespace App\Enums;

enum StatusTicket: string
{
    case TRIAGEM = 'TRIAGEM';
    case A_FAZER = 'A_FAZER';
    case EM_ANDAMENTO = 'EM_ANDAMENTO';
    case EM_REVISAO = 'EM_REVISAO';
    case CONCLUIDO = 'CONCLUIDO';
}
