<?php

use App\Models\StageAutomation;
use Illuminate\Database\Migrations\Migration;

/**
 * Semeia o playbook da etapa "Reunião Realizada": envia a proposta (PDF) pedindo análise
 * e, 2 dias depois, um follow-up. O passo do PDF fica inerte até o usuário anexar o arquivo
 * pela tela /admin/automacoes (o tick pula media sem asset). Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stageKey = config('services.crm.stage_meeting_done', 'reuniao-realizada');

        $automation = StageAutomation::firstOrCreate(
            ['stage_key' => $stageKey],
            ['name' => 'Pós-reunião — proposta e follow-up', 'enabled' => true],
        );

        if ($automation->steps()->exists()) {
            return; // já configurado — não sobrescreve edições do usuário
        }

        $automation->steps()->create([
            'position' => 0,
            'type' => 'media',
            'delay_minutes' => 0,
            'text' => "Oi, {nome}! Foi ótimo nosso papo 🙌\n".
                "Como combinei, tô te mandando aqui a proposta com os detalhes do que conversamos — valores, escopo e como funciona na prática.\n\n".
                'Dá uma olhada com calma e qualquer dúvida me chama por aqui mesmo. Fico à disposição! 👇',
        ]);

        $automation->steps()->create([
            'position' => 1,
            'type' => 'text',
            'delay_minutes' => 2880, // 2 dias
            'text' => "Oi, {nome}, tudo bem? 😊\n".
                "Passando pra saber se você conseguiu analisar a proposta que te mandei.\n".
                'O que achou? Faz sentido a gente seguir pros próximos passos, ou ficou alguma dúvida que eu possa esclarecer?',
        ]);
    }

    public function down(): void
    {
        $stageKey = config('services.crm.stage_meeting_done', 'reuniao-realizada');
        $automation = StageAutomation::where('stage_key', $stageKey)->first();
        if ($automation) {
            $automation->steps()->delete();
            $automation->delete();
        }
    }
};
