<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab do chat = um TIME (SDR, Closer, CS): agrupa etapas do funil e carrega o
 * objetivo que a IA persegue com os leads que estão nessas etapas.
 */
class ChatTab extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['stages' => 'array', 'qualified' => 'array', 'show_hidden' => 'boolean'];

    /**
     * A conversa entra nesta tab? Etapa E triagem — as duas têm de bater.
     *
     * Régua única do filtro: a lista, os contadores do topo e o contador da própria tab
     * chamam daqui. Quando cada um repetia a condição, bastava mexer numa para o número
     * da bolinha discordar do que a lista mostrava.
     */
    public function combina(Conversation $conv): bool
    {
        if (! in_array($conv->stage, (array) $this->stages, true)) {
            return false;
        }

        $tri = array_filter((array) $this->qualified, fn ($v) => $v !== '' && $v !== null);
        if (! $tri) {
            return true; // sem filtro de triagem = todos, que é como as tabs antigas eram
        }

        // NULL é "ainda não triado", um estado de verdade — nunca o mesmo que desqualificado.
        $estado = $conv->qualified === null ? 'sem' : ($conv->qualified ? '1' : '0');

        return in_array($estado, array_map('strval', $tri), true);
    }

    /**
     * Time responsável por uma etapa do funil. A primeira tab que lista a etapa
     * ganha — etapa em duas tabs é configuração ambígua, e a ordem da tela é a
     * resposta menos surpreendente.
     *
     * Lixeira (`show_hidden`) fica de fora: ela é um recorte de arquivo morto, não um time.
     * Se ganhasse a etapa, a IA passaria a perseguir o objetivo escrito na lixeira com leads
     * vivos — e o sintoma apareceria longe daqui, na resposta errada mandada ao cliente.
     */
    public static function forStage(?string $stage): ?self
    {
        if (! $stage) {
            return null;
        }

        return static::where('show_hidden', false)->orderBy('position')->orderBy('id')->get()
            ->first(fn (self $t) => in_array($stage, (array) $t->stages, true));
    }
}
