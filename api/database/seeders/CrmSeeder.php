<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Task;
use Illuminate\Database\Seeder;

class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedConversations();
        $this->seedDeals();
        $this->seedTasks();
    }

    private function seedConversations(): void
    {
        $data = [
            [
                'slug' => 'mariana', 'name' => 'Mariana Costa', 'initials' => 'MC', 'color' => '#b5446e', 'online' => true,
                'status_text' => 'online · responde rápido', 'role' => 'Gerente Comercial · Vértice',
                'deal_value' => 'R$ 4.200', 'deal_unit' => '/mês', 'stage' => 'Negociação', 'stage_color' => '#ffb443', 'prob' => 72, 'hot' => true,
                'preview' => 'Perfeito, pode enviar a proposta 🙌', 'time' => '09:22', 'unread' => 2,
                'tags' => [['label' => 'Plano Pro', 'color' => '#7c6cf5'], ['label' => '🔥 Quente', 'color' => '#ff7a45']],
                'phone' => '+55 11 98765-4321', 'email' => 'mariana@vertice.com.br', 'company' => 'Construtora Vértice', 'origin' => 'Anúncio · WhatsApp', 'responsible' => 'Ana Beatriz',
                'interactions' => [
                    ['title' => 'Proposta enviada via WhatsApp', 'meta' => 'Proposta_Vértice_Pro.pdf · hoje, 09:19', 'color' => '#25D366'],
                    ['title' => 'Áudio recebido', 'meta' => 'Detalhou tamanho da equipe · hoje, 09:15', 'color' => '#7c6cf5'],
                    ['title' => 'Lead criado', 'meta' => 'Via anúncio no WhatsApp · hoje, 09:12', 'color' => '#53bdeb'],
                    ['title' => 'Demonstração agendada', 'meta' => 'Hoje às 14:00 · vídeo + tela', 'color' => '#ffb443'],
                ],
                'thread' => [
                    ['type' => 'divider', 'label' => 'HOJE'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Oi! Vi o anúncio de vocês sobre o sistema de gestão 👋', 'time' => '09:12'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Olá, Mariana! Que bom seu contato 😊 Posso te explicar como o Vértice funciona pra sua equipe?', 'time' => '09:14'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Pode sim. Somos uma equipe de 12 pessoas no comercial.', 'time' => '09:15'],
                    ['type' => 'voice', 'is_out' => false, 'dur' => '0:24', 'time' => '09:15'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Perfeito! Para 12 usuários o plano Pro é o ideal — inclui funil ilimitado, automações e atendimento via WhatsApp integrado.', 'time' => '09:18'],
                    ['type' => 'file', 'is_out' => true, 'file_name' => 'Proposta_Vértice_Pro.pdf', 'meta' => '3 páginas · 248 KB', 'time' => '09:19'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Perfeito, pode enviar a proposta detalhada 🙌', 'time' => '09:22'],
                ],
            ],
            [
                'slug' => 'ricardo', 'name' => 'Ricardo Alves', 'initials' => 'RA', 'color' => '#3b6ea5', 'online' => true,
                'status_text' => 'online', 'role' => 'Autônomo', 'deal_value' => 'R$ 6.300', 'deal_unit' => '', 'stage' => 'Contato feito', 'stage_color' => '#7c6cf5', 'prob' => 30, 'hot' => false,
                'preview' => '🎤 Áudio · 0:34', 'time' => '09:05', 'unread' => 0, 'tags' => [],
                'phone' => '+55 21 99111-2030', 'email' => 'ricardo.alves@gmail.com', 'company' => 'Autônomo', 'origin' => 'WhatsApp', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Lead criado', 'meta' => 'Via WhatsApp · hoje, 09:01', 'color' => '#53bdeb']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'HOJE'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Bom dia! Vocês atendem autônomo também?', 'time' => '09:01'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Bom dia, Ricardo! Atendemos sim 👍 Qual o seu segmento?', 'time' => '09:03'],
                    ['type' => 'voice', 'is_out' => false, 'dur' => '0:34', 'time' => '09:05'],
                ],
            ],
            [
                'slug' => 'vertice', 'name' => 'Construtora Vértice', 'initials' => 'CV', 'color' => '#5b6470', 'online' => false,
                'status_text' => 'visto por último hoje', 'role' => 'Setor de compras', 'deal_value' => 'R$ 18.900', 'deal_unit' => '', 'stage' => 'Negociação', 'stage_color' => '#ffb443', 'prob' => 48, 'hot' => false,
                'preview' => 'Qual o prazo de entrega?', 'time' => '08:47', 'unread' => 1, 'tags' => [['label' => 'B2B', 'color' => '#53bdeb']],
                'phone' => '+55 11 3344-5566', 'email' => 'compras@vertice.com.br', 'company' => 'Construtora Vértice', 'origin' => 'Indicação', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Proposta recebida', 'meta' => 'hoje, 08:40', 'color' => '#25D366']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'HOJE'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Recebemos a proposta, obrigado.', 'time' => '08:40'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Imagina! Qualquer dúvida estou à disposição.', 'time' => '08:42'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Qual o prazo de entrega?', 'time' => '08:47'],
                ],
            ],
            [
                'slug' => 'juliana', 'name' => 'Juliana Mendes', 'initials' => 'JM', 'color' => '#8e5bb5', 'online' => true,
                'status_text' => 'online', 'role' => 'JM Consultoria', 'deal_value' => 'R$ 9.600', 'deal_unit' => '', 'stage' => 'Fechado', 'stage_color' => '#25D366', 'prob' => 100, 'hot' => false,
                'preview' => 'Obrigada! Ansiosa pra começar', 'time' => 'Ontem', 'unread' => 0, 'tags' => [['label' => 'Cliente', 'color' => '#25D366']],
                'phone' => '+55 31 98888-7777', 'email' => 'juliana@jmconsultoria.com', 'company' => 'JM Consultoria', 'origin' => 'Indicação', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Contrato assinado', 'meta' => 'ontem', 'color' => '#25D366']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'ONTEM'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Contrato assinado! Seja muito bem-vinda 🎉', 'time' => 'Ontem'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Obrigada! Ansiosa pra começar', 'time' => 'Ontem'],
                ],
            ],
            [
                'slug' => 'pedro', 'name' => 'Pedro Santos', 'initials' => 'PS', 'color' => '#c77d3a', 'online' => false,
                'status_text' => 'visto ontem', 'role' => 'Distribuidora PS', 'deal_value' => 'R$ 8.400', 'deal_unit' => '', 'stage' => 'Proposta enviada', 'stage_color' => '#3aa6ff', 'prob' => 40, 'hot' => false,
                'preview' => '📷 Foto', 'time' => 'Ontem', 'unread' => 0, 'tags' => [],
                'phone' => '+55 41 99655-1212', 'email' => 'pedro@distribuidoraps.com', 'company' => 'Distribuidora PS', 'origin' => 'Site', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Planos enviados', 'meta' => 'ontem', 'color' => '#25D366']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'ONTEM'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Pode me mandar os planos por escrito?', 'time' => 'Ontem'],
                    ['type' => 'file', 'is_out' => true, 'file_name' => 'Planos_Distribuidora.pdf', 'meta' => '2 páginas · 180 KB', 'time' => 'Ontem'],
                ],
            ],
            [
                'slug' => 'fernanda', 'name' => 'Fernanda Lima', 'initials' => 'FL', 'color' => '#4a8f7b', 'online' => false,
                'status_text' => 'visto segunda', 'role' => 'Clínica Bem Viver', 'deal_value' => 'R$ 10.500', 'deal_unit' => '', 'stage' => 'Contato feito', 'stage_color' => '#7c6cf5', 'prob' => 35, 'hot' => false,
                'preview' => 'Obrigada pelo atendimento!', 'time' => 'Seg', 'unread' => 0, 'tags' => [],
                'phone' => '+55 51 98777-3344', 'email' => 'contato@bemviver.com.br', 'company' => 'Clínica Bem Viver', 'origin' => 'Indicação', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Lead criado', 'meta' => 'segunda', 'color' => '#53bdeb']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'SEGUNDA'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Tudo certo com o seu acesso?', 'time' => 'Seg'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Sim! Obrigada pelo atendimento!', 'time' => 'Seg'],
                ],
            ],
            [
                'slug' => 'cafe', 'name' => 'Café Aurora Ltda', 'initials' => 'CA', 'color' => '#7a5bb5', 'online' => false,
                'status_text' => 'visto segunda', 'role' => 'Diretoria', 'deal_value' => 'R$ 14.300', 'deal_unit' => '', 'stage' => 'Proposta enviada', 'stage_color' => '#3aa6ff', 'prob' => 25, 'hot' => false,
                'preview' => 'Vamos avaliar internamente', 'time' => 'Seg', 'unread' => 0, 'tags' => [],
                'phone' => '+55 11 2233-9090', 'email' => 'diretoria@cafeaurora.com.br', 'company' => 'Café Aurora Ltda', 'origin' => 'Site', 'responsible' => 'Ana Beatriz',
                'interactions' => [['title' => 'Proposta enviada', 'meta' => 'segunda', 'color' => '#25D366']],
                'thread' => [
                    ['type' => 'divider', 'label' => 'SEGUNDA'],
                    ['type' => 'text', 'is_out' => true, 'text' => 'Conseguiram avaliar a proposta?', 'time' => 'Seg'],
                    ['type' => 'text', 'is_out' => false, 'text' => 'Vamos avaliar internamente e retornamos', 'time' => 'Seg'],
                ],
            ],
        ];

        foreach ($data as $pos => $row) {
            $thread = $row['thread'];
            unset($row['thread']);
            $row['position'] = $pos;
            $conv = Conversation::create($row);
            foreach ($thread as $i => $msg) {
                $msg['position'] = $i;
                $conv->messages()->create($msg);
            }
        }
    }

    private function seedDeals(): void
    {
        $deals = [
            ['stage' => 'novo', 'name' => 'Café Aurora Ltda', 'sub' => 'João Carvalho', 'value' => 'R$ 3.900', 'tag' => 'Site'],
            ['stage' => 'novo', 'name' => 'Studio Norte', 'sub' => 'Renata Dias', 'value' => 'R$ 5.100', 'tag' => 'Indicação'],
            ['stage' => 'novo', 'name' => 'Loja Maré', 'sub' => 'Tiago Melo', 'value' => 'R$ 5.200', 'tag' => 'WhatsApp'],
            ['stage' => 'contato', 'name' => 'Ricardo Alves', 'sub' => 'Autônomo', 'value' => 'R$ 6.300', 'tag' => 'Áudio novo'],
            ['stage' => 'contato', 'name' => 'Fernanda Lima', 'sub' => 'Clínica Bem Viver', 'value' => 'R$ 10.500', 'tag' => 'Indicação'],
            ['stage' => 'proposta', 'name' => 'Pedro Santos', 'sub' => 'Distribuidora PS', 'value' => 'R$ 8.400', 'tag' => 'Enviada'],
            ['stage' => 'proposta', 'name' => 'Café Aurora Ltda', 'sub' => 'Diretoria', 'value' => 'R$ 14.300', 'tag' => 'Aguardando'],
            ['stage' => 'negociacao', 'name' => 'Mariana Costa', 'sub' => 'Vértice · Plano Pro', 'value' => 'R$ 4.200/mês', 'tag' => '72%', 'hot' => true, 'tag_strong' => true],
            ['stage' => 'negociacao', 'name' => 'Construtora Vértice', 'sub' => 'Setor de compras', 'value' => 'R$ 18.900', 'tag' => '48%'],
            ['stage' => 'fechado', 'name' => 'Juliana Mendes', 'sub' => 'JM Consultoria', 'value' => 'R$ 9.600', 'tag' => 'Ganho', 'won' => true, 'tag_strong' => true],
        ];
        foreach ($deals as $pos => $deal) {
            $deal['position'] = $pos;
            Deal::create($deal);
        }
    }

    private function seedTasks(): void
    {
        $tasks = [
            ['column' => 'todo', 'title' => 'Configurar integração do WhatsApp para a Vértice', 'client' => 'Mariana Costa · Vértice', 'priority' => 'alta', 'due' => '18 jun', 'type' => 'Novo recurso'],
            ['column' => 'todo', 'title' => 'Enviar tabela de preços atualizada', 'client' => 'Construtora Vértice', 'priority' => 'media', 'due' => '19 jun', 'type' => 'Suporte'],
            ['column' => 'doing', 'title' => 'Montar proposta personalizada do Plano Pro', 'client' => 'Mariana Costa', 'priority' => 'alta', 'due' => 'Hoje', 'type' => 'Novo recurso'],
            ['column' => 'doing', 'title' => 'Corrigir bug no relatório de conversão', 'client' => 'Interno', 'priority' => 'media', 'due' => 'Hoje', 'type' => 'Bug'],
            ['column' => 'review', 'title' => 'Roteiro da demonstração ao vivo', 'client' => 'Equipe comercial', 'priority' => 'media', 'due' => 'Hoje', 'type' => 'Outro'],
            ['column' => 'done', 'title' => 'Onboarding inicial — Juliana Mendes', 'client' => 'JM Consultoria', 'priority' => 'baixa', 'due' => 'Ontem', 'type' => 'Suporte'],
            ['column' => 'done', 'title' => 'Importar contatos do CSV', 'client' => 'Interno', 'priority' => 'baixa', 'due' => 'Seg', 'type' => 'Suporte'],
        ];
        foreach ($tasks as $pos => $task) {
            $task['position'] = $pos;
            Task::create($task);
        }
    }
}
