<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\WaAccount;
use App\Support\Evolution;

/**
 * O gestor falando com o dono pelo WhatsApp: alerta (saldo acabando, teto estourado,
 * anúncio reprovado) e relatório do dia.
 *
 * Sai por um número da EVOLUTION, de propósito: pela API oficial, mensagem fora da janela
 * de 24h exige template aprovado, e alerta de saldo que depende de template não chega.
 *
 * Todo aviso tem uma chave e um intervalo: o gestor revisa de hora em hora, e sem isso o
 * mesmo "saldo baixo" chegaria 24 vezes por dia — que é o jeito mais rápido de alguém
 * silenciar o número e perder o aviso que importa.
 */
class Avisos
{
    public function __construct(private MarketingAutopilot $cfg, private bool $simular = false, private string $quem = 'Gestor de tráfego') {}

    /** @return bool se foi enviado (ou, em simulação, se teria sido) */
    public function enviar(string $chave, string $texto, int $horas = 12): bool
    {
        $repetido = MarketingAutopilotEvent::where('kind', 'alerta')
            ->where('simulated', false)
            ->where('created_at', '>=', now()->subHours($horas))
            ->where('data->chave', $chave)
            ->exists();
        if ($repetido) {
            return false;
        }

        $fone = preg_replace('/\D/', '', (string) $this->cfg->alert_phone);
        $conta = $this->conta();
        $enviado = false;
        $obs = '';

        if ($this->simular) {
            $obs = ' (simulação — não enviado)';
        } elseif ($fone === '') {
            $obs = ' (sem WhatsApp de aviso configurado — só no painel)';
        } elseif (! $conta) {
            $obs = ' (nenhum número da Evolution para enviar — só no painel)';
        } else {
            $enviado = Evolution::sendText($fone, "🤖 *{$this->quem}*\n\n".$texto, instance: $conta->instance) !== null;
            $obs = $enviado ? '' : ' (FALHOU o envio pelo WhatsApp)';
        }

        MarketingAutopilotEvent::create([
            'kind' => 'alerta',
            'message' => $texto.$obs,
            'data' => ['chave' => $chave, 'enviado' => $enviado],
            'simulated' => $this->simular,
        ]);

        return $enviado || $this->simular;
    }

    /**
     * Manda uma imagem ao dono (o criativo que o designer fez para ele ver).
     *
     * @return string|null o id da mensagem no WhatsApp — é como a resposta dele à imagem é ligada ao criativo
     */
    public function enviarImagem(string $arquivo, string $legenda, string $nome): ?string
    {
        $fone = preg_replace('/\D/', '', (string) $this->cfg->alert_phone);
        $conta = $this->conta();
        if ($this->simular || $fone === '' || ! $conta || ! is_file($arquivo)) {
            return null;
        }
        $mime = mime_content_type($arquivo) ?: 'image/png';

        return Evolution::sendMedia($fone, base64_encode((string) file_get_contents($arquivo)), $mime, $nome, $legenda, 'image', $conta->instance) ?: null;
    }

    /**
     * O número escolhido no painel, ou o primeiro da Evolution — preferindo um que atende,
     * mas aceitando o "só fotos": ele segue conectado, e mandar 1–2 avisos por dia ao dono
     * não é o tráfego de mensagem que essa marcação existe para evitar.
     */
    private function conta(): ?WaAccount
    {
        $q = WaAccount::where(fn ($w) => $w->whereNull('provider')->orWhere('provider', '!=', 'cloud'))->whereNotNull('instance')->where('instance', '!=', '');
        if ($this->cfg->alert_wa_account_id) {
            return (clone $q)->whereKey($this->cfg->alert_wa_account_id)->first();
        }

        return $q->where('is_active', true)->orderByRaw('COALESCE(avatars_only, 0)')->orderBy('id')->first();
    }
}
