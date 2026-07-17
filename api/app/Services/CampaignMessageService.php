<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\MemoryChunk;
use App\Models\StyleProfile;
use App\Models\StyleRule;
use App\Models\StyleSample;
use App\Support\Claude;

/**
 * Gera a mensagem de PRIMEIRO CONTATO (prospecção ativa) de uma campanha — fria,
 * personalizada por contato e no estilo/voz configurado. Diferente do AiReplyService,
 * aqui não há histórico: é a abordagem inicial guiada pelo objetivo da campanha.
 */
class CampaignMessageService
{
    public function generate(Campaign $campaign, CampaignContact $contact): ?string
    {
        if (! config('services.claude.oauth_token')) {
            return null;
        }

        $nome = trim((string) $contact->name) ?: 'o contato';

        // Variáveis extras do CSV (ex.: empresa, cargo) — ajudam a personalizar.
        $vars = '';
        foreach ((array) $contact->vars as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && ! is_array($v)) {
                $vars .= "- {$k}: {$v}\n";
            }
        }
        $varsBlock = $vars !== '' ? "DADOS DO CONTATO:\n{$vars}\n" : '';

        $voz = $this->voiceContext();

        // Saudação coerente com o horário (Brasília).
        $hour = (int) now()->format('G');
        $saudacao = $hour >= 5 && $hour < 12 ? 'Bom dia' : ($hour >= 12 && $hour < 18 ? 'Boa tarde' : 'Boa noite');

        $objetivo = trim((string) $campaign->objective) ?: 'iniciar uma conversa e despertar interesse.';

        $prompt = <<<TXT
        Você é o ATENDENTE escrevendo a PRIMEIRA mensagem de WhatsApp para um contato que ainda NÃO te conhece (prospecção ativa / abordagem fria).
        Nome do contato: {$nome}.

        {$varsBlock}{$voz}
        OBJETIVO DESTA CAMPANHA:
        {$objetivo}

        Escreva a mensagem de abertura.
        Regras de saída:
        - É um primeiro contato frio: seja natural, humano e educado; nada de parecer robô ou spam.
        - Comece com "{$saudacao}" e, se souber, use o primeiro nome do contato.
        - Use EXATAMENTE o estilo/voz e as regras acima (se houver).
        - Conduza sutilmente ao objetivo da campanha — sem pressão, sem prometer preço/política que você não tem.
        - Português do Brasil, no máximo 2-3 frases curtas.
        - Sem aspas, sem rótulos — só o texto da mensagem.
        TXT;

        $out = Claude::run($prompt, 60);

        return $out !== null && trim($out) !== '' ? trim($out) : null;
    }

    /** Perfil de voz + regras + exemplos + conhecimento geral (sem conversa específica). */
    private function voiceContext(): string
    {
        $ctx = '';

        $style = StyleProfile::first()?->summary;
        if ($style) {
            $ctx .= "COMO VOCÊ (atendente) FALA:\n{$style}\n\n";
        }

        $rules = StyleRule::orderBy('id')->pluck('rule');
        if ($rules->isNotEmpty()) {
            $ctx .= "REGRAS QUE VOCÊ SEMPRE SEGUE:\n- ".$rules->implode("\n- ")."\n\n";
        }

        $samples = StyleSample::latest('id')->take(4)->pluck('text');
        if ($samples->isNotEmpty()) {
            $ctx .= "EXEMPLOS DE MENSAGENS SUAS:\n- ".$samples->implode("\n- ")."\n\n";
        }

        $chunks = MemoryChunk::limit(5)->get();
        if ($chunks->isNotEmpty()) {
            $ctx .= "CONHECIMENTO (use quando relevante):\n";
            foreach ($chunks as $c) {
                $ctx .= "- [{$c->kind}] {$c->gatilho}: {$c->conteudo}\n";
            }
            $ctx .= "\n";
        }

        return $ctx;
    }
}
