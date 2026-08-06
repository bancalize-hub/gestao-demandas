<?php

namespace App\Console\Commands;

use App\Models\ChatTab;
use App\Models\MemoryChunk;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Carrega a base de TUTORIAIS da plataforma white-label no time de CS.
 *
 * O cliente que já comprou não pergunta preço — ele pergunta "onde eu troco a logo".
 * Cada item aqui é uma tarefa de tela, com o caminho exato do menu, para a IA ensinar
 * em vez de descrever por cima.
 *
 * Reexecutável: casa por gatilho dentro do time (updateOrCreate), então corrigir um
 * texto e rodar de novo atualiza em vez de duplicar.
 */
class SeedTutoriaisCs extends Command
{
    protected $signature = 'memoria:tutoriais-cs {--company=1 : Empresa dona da base} {--tab=CS : Nome da aba/time}';

    protected $description = 'Carrega os tutoriais de uso da plataforma no conhecimento do time de CS';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');

        return app(Tenancy::class)->run($companyId, function () use ($companyId) {
            $tab = ChatTab::where('name', $this->option('tab'))->first();
            if (! $tab) {
                $this->error("Time \"{$this->option('tab')}\" não existe na empresa {$companyId}.");

                return self::FAILURE;
            }

            $novos = 0;
            $atualizados = 0;

            foreach ($this->chunks() as [$kind, $gatilho, $keywords, $conteudo]) {
                // As colunas têm limite (255 nas palavras-chave, 2000 no conteúdo) e o erro do
                // banco vem no meio da carga, deixando metade dentro. Falha antes, dizendo qual.
                if (mb_strlen($keywords) > 255 || mb_strlen(trim(preg_replace('/\s*\n\s*/', ' ', $conteudo))) > 2000) {
                    $this->error("Item \"{$gatilho}\" passou do limite (keywords 255 / conteúdo 2000). Nada foi gravado.");

                    return self::FAILURE;
                }

                $chunk = MemoryChunk::firstOrNew(['chat_tab_id' => $tab->id, 'gatilho' => $gatilho]);
                $existia = $chunk->exists;
                $chunk->fill([
                    'kind' => $kind,
                    'keywords' => $keywords,
                    'conteudo' => trim(preg_replace('/\s*\n\s*/', ' ', $conteudo)),
                ])->save();
                $existia ? $atualizados++ : $novos++;
            }

            $this->info("Time {$tab->name} (empresa {$companyId}): {$novos} novo(s), {$atualizados} atualizado(s).");
            $this->info('Total de tutoriais no time: '.MemoryChunk::where('chat_tab_id', $tab->id)->where('kind', 'tutorial')->count());

            return self::SUCCESS;
        });
    }

    /** [tipo, gatilho, palavras-chave, conteúdo] */
    private function chunks(): array
    {
        return [
            // ---- como ENSINAR (postura, não tutorial) -------------------------------
            ['procedimento', 'como ensinar o cliente a mexer no sistema',
                'ensinar, tutorial, passo a passo, como faço, onde fica, não sei mexer, ajuda, dúvida de uso',
                'Cliente que já comprou perguntando como fazer algo no sistema: dê o CAMINHO EXATO do menu (ex.: White Label
                 → Configurações → aba Aparência), na ordem dos cliques, com o nome dos botões como aparecem na tela. Um
                 procedimento por mensagem, curto. Se a tarefa tiver muitos passos, mande o primeiro bloco e pergunte se
                 chegou nessa tela antes de continuar. Nunca invente nome de menu ou botão: se você não tem o passo a passo
                 daquela função, diga que vai confirmar com o time em vez de chutar.', ],

            // ---- jornadas -----------------------------------------------------------
            ['tutorial', 'primeira configuração da plataforma, do zero ao primeiro lojista',
                'começar, primeira vez, configurar do zero, roteiro, implantação, subir a plataforma, deixar pronta',
                'Roteiro completo logo depois de contratar o white label. ETAPA 1 (identidade e funcionalidades): em White
                 Label → Configurações → aba Geral, defina Nome e Descrição da plataforma, ligue os recursos que vai
                 oferecer (Cartão, Boleto, Criptomoedas, Maquininha, Empréstimo, Chaves PIX) e escolha os itens do menu dos
                 lojistas; na aba Aparência, envie logo, favicon e banner de taxas, escolha a cor primária e ajuste as telas
                 de login e registro. ETAPA 2 (e-mail e regras financeiras): na aba Outros, preencha o SMTP e use Testar
                 conexão SMTP antes de salvar, e defina reserva, limites de transferência e pré-chargeback; em White Label →
                 E-mails personalizados, ajuste os textos de Conta criada, Conta bloqueada, Solicitação aprovada e recusada.
                 ETAPA 3 (adquirente e cobrança): em White Label → Adquirentes, clique em Adicionar Adquirente, cadastre as
                 credenciais, ligue os métodos (PIX, Boleto, Cartão), cadastre a URL de webhook no portal dela e informe em
                 Editar taxas o que ela cobra de você. ETAPA 4 (regras e primeiro lojista): crie os termos em White Label →
                 Termos de Uso; quando o primeiro lojista se cadastrar, aprove em Admin → Solicitações de Gateway e ajuste
                 as taxas dele em Admin → Todas as contas → Alterar taxas. Faça a etapa da adquirente com calma, é ela que
                 processa o dinheiro: teste em Sandbox antes de ir para Produção.', ],

            ['tutorial', 'liberar um lojista novo, do cadastro à primeira venda',
                'lojista novo, cadastro, aprovar lojista, liberar, documentos, primeira venda, onboarding do lojista',
                'ETAPA 1: em Admin → Solicitações de Gateway, clique no card do lojista, revise os dados e baixe os
                 documentos (contrato social, documento, selfie, comprovante de endereço, faturamento) e confira faturamento
                 estimado, ticket médio, site e produtos declarados. ETAPA 2: clique em Responder solicitação; no passo 1
                 revise as permissões (transferências, antecipação, Cartão/Boleto/PIX) e no passo 2 escolha Aprovado (a conta
                 é ativada e já pode vender) ou Reprovado, e confirme. ETAPA 3: em Admin → Todas as contas, abra a conta
                 aprovada, use Alterar taxas para definir o que ele paga por PIX, Boleto e Cartão (por parcela) e, se
                 precisar, Adquirentes customizadas para escolher qual adquirente processa cada método dele. ETAPA 4: quando
                 ele vender, a venda aparece em Admin → Todas as transações; se pedir saque, aprove em Admin →
                 Transferências. Analise os documentos com atenção: depois de aprovada, a conta já transaciona de verdade.', ],

            ['tutorial', 'rotina diária de operação (checklist do dia)',
                'rotina, dia a dia, checklist, o que olhar todo dia, diário, pendências',
                'PANORAMA: comece em Admin → Dashboard e confira volume, saldos, vendas por método e status das contas,
                 ajustando período e empresa para investigar número fora da curva. APROVAÇÕES PENDENTES: Admin →
                 Transferências (aprovar/reprovar saques Pendentes), Admin → Antecipações (pedidos pendentes), Admin →
                 Solicitações de maquininha (aprovar pedidos e registrar envios) e Admin → Empréstimos (pedidos aguardando
                 análise). RELACIONAMENTO: Admin → Solicitações de Gateway para liberar lojistas novos e Admin →
                 Gerenciamento de contas para acompanhar no Kanban as contas em risco ou paradas e fazer follow-up. Deixe
                 saques e antecipações pendentes para o começo do dia: é o que mais impacta a experiência do lojista.', ],

            ['tutorial', 'entender o seu dinheiro: o que o lojista paga, o que a adquirente cobra e o seu lucro',
                'lucro, margem, quanto ganho, custo, taxa, dinheiro, ganho, resultado',
                'São três pontas. 1) O que o lojista paga a você: Admin → Todas as contas → Alterar taxas (por conta). 2) O
                 que a adquirente cobra de você (seu custo): White Label → Adquirentes → Editar taxas. 3) O seu lucro: a
                 diferença entre os dois, consolidada em Admin → Lucratividade. Relatórios: Admin → Lucratividade (lucro por
                 conta, margem e volume no período, com exportação), Admin → Faturamento por conta (quanto cada lojista
                 faturou por método) e Admin → Faturamento por Adquirente (volume por adquirente). Em White Label →
                 Faturamento você vê a SUA fatura como dono do white label, separada do que os lojistas geram. Concentrar
                 volume numa adquirente só é risco: use o Faturamento por Adquirente para distribuir e negociar custo.', ],

            // ---- configurações / white label ---------------------------------------
            ['tutorial', 'trocar a logo, o favicon, as cores e o visual da plataforma',
                'logo, trocar a logo, mudar a logo, trocar logo, mudar logo, colocar minha logo, minha marca, favicon, ícone, cor, cores, mudar as cores, visual, aparência, tema, banner, identidade visual, tela de login',
                'Vá em White Label → Configurações → aba Aparência. Em Imagens de personalização envie: Logo (ícone ou
                 quadrado) — que é o favicon, o ícone da aba do navegador —, Logo, Logo (modo escuro) e Banner de Tarifas;
                 depois clique em Salvar Imagens e as novas imagens passam a aparecer para todos os usuários. Em Cores
                 principais escolha a cor Primário: ela pinta botões, links e destaques da plataforma inteira. Em Fundo da
                 página ajuste a cor de fundo separadamente para Modo claro e Modo escuro (Resetar para o padrão volta a
                 #FAFAF8 no claro e #090E12 no escuro). Em Login e Registro defina a Posição do formulário (Esquerda, Centro
                 ou Direita) e envie a Imagem de Fundo do Login e do Registro — para trocar uma imagem já salva, use Remover
                 imagem salva antes de subir a nova — e clique em Salvar Login & Registro. Para voltar tudo ao original, use
                 Resetar Tema no fim da aba.', ],

            ['tutorial', 'definir o nome da plataforma, ligar recursos e escolher o menu dos lojistas',
                'nome da plataforma, descrição, recursos, módulos, ativar cripto, ativar boleto, ativar maquininha, menu, esconder',
                'Vá em White Label → Configurações → aba Geral. Preencha Nome (aparece em telas e no título do navegador) e
                 Descrição — os dois são obrigatórios, sem eles o sistema avisa Preencha todos os campos obrigatórios e não
                 salva. Em Recursos da plataforma ligue ou desligue o que os lojistas veem: Criptomoedas, Visualização de
                 comprovantes, Pagamento via QR Code, Minhas chaves Pix, Cartão de crédito, Recarga de celular, Pagamento de
                 boleto, Empréstimo e Maquininha de cartão — cada chave mostra ou esconde o card correspondente na carteira
                 do lojista. Ligando a Maquininha, ajuste o Limite de maquininhas por pedido (1 a 10, padrão 3). Em Links no
                 menu lateral escolha quais itens aparecem para o lojista: Transações, Clientes, Produtos, Cursos,
                 Assinaturas, Checkout, Loja Online, Aplicativos e Recebimentos. Clique em Salvar: a página recarrega
                 sozinha, é assim que as configurações entram em vigor. Atenção: ligar o recurso aqui só mostra o botão — o
                 método precisa estar ativo em alguma adquirente para funcionar de verdade.', ],

            ['tutorial', 'configurar o e-mail próprio da plataforma (SMTP)',
                'smtp, email, e-mail, remetente, servidor de email, tls, não chega email, email não chega, chegando email, enviar email, domínio próprio',
                'Vá em White Label → Configurações → aba Outros, seção Configurações de E-mail (SMTP). Preencha Host SMTP,
                 Porta (geralmente 587), Usuário SMTP, Senha SMTP, Criptografia (Nenhuma, TLS ou SSL), E-mail remetente
                 (From) e Nome remetente. Clique em Testar conexão SMTP — o botão só habilita depois de host, usuário, senha
                 e remetente preenchidos — e o sistema envia um e-mail de teste na hora. Acompanhe o selo no topo da seção:
                 ele vai de Não configurado para Configurado e depois Testado e funcionando ou Erro de conexão. Só depois do
                 teste dar certo clique em SALVAR. Sem SMTP configurado, os e-mails de assinatura (Pix, Boleto) saem pelo
                 servidor padrão da plataforma, não pelo seu domínio.', ],

            ['tutorial', 'regras financeiras globais: reserva, limite de saque, pré-chargeback e estorno',
                'reserva financeira, limite de saque, limite diário, pré-chargeback, chargeback, estorno, retenção, regras globais',
                'Vá em White Label → Configurações → aba Outros (Configurações privadas). Devolver taxas da transação em
                 estornos: ligado, as taxas voltam para o lojista quando ele estorna uma venda. Pré-chargeback: ative
                 Serviço Pré-Chargeback ativo (Alertas) e informe o Valor do Pré-Chargeback Mastercard e Visa (ambos
                 precisam ser maiores que zero para salvar); há ainda opções de cobrar por parcela e de marcar chargebacks
                 como pré-chargeback. Limite diário de transferências (Global): ao ligar aparecem os campos de Valor do
                 limite diário (mínimo R$ 100,00) e Limite máximo por saque (vazio = sem limite). Reserva financeira
                 habilitada (Global): ao ligar surgem três cards (PIX, Boleto e Cartão) com Percentual de reserva e Dias de
                 retenção — a plataforma segura esse percentual de cada venda pelo período definido antes de liberar o
                 saldo. Origem dos débitos: Descontar chargeback do saldo disponível, Descontar estorno do saldo disponível
                 e o seletor Debitar estorno/chargeback de (Automático, Disponível ou A receber). Nada vale até clicar em
                 SALVAR no fim da página.', ],

            ['tutorial', 'ativar login com Google, Facebook ou X na plataforma',
                'login social, google, facebook, twitter, oauth, client id, client secret, redirect uri, entrar com google',
                'Vá em White Label → Configurações → aba Social. Cada provedor (Google, Facebook e X) tem um card com o selo
                 Credenciais configuradas ou Credenciais pendentes. Crie um aplicativo no painel de desenvolvedor do
                 provedor (por exemplo o console do Google Cloud) e copie de lá o Client ID e o Client Secret; cole nos
                 campos do card (o olhinho mostra/esconde o Secret). Copie o Redirect URI exibido no card pelo botão Copiar
                 e cadastre-o no aplicativo do provedor — sem esse passo o login social falha mesmo com as credenciais
                 certas. Ligue a chave Login com o provedor e clique em Salvar alterações: o botão dele passa a aparecer na
                 tela de login dos lojistas.', ],

            ['tutorial', 'configurações públicas e exigências no cadastro do lojista',
                'opt-in, lgpd, mostrar saldo, valor líquido, pessoa física, comprovante de endereço, onboarding, exigir documento',
                'Em White Label → Configurações → aba Outros, seção Configurações públicas, ficam as chaves que mudam o que
                 o lojista vê: Mostrar opt-in no checkout (LGPD), Mostrar valor líquido das transações, Cadastro Pessoa
                 Física habilitado (permite cadastro com CPF), Mostrar valor bruto no dashboard, Mostrar resumo do saldo e
                 Mostrar percentual de antecipação. As chaves de cadastro — Solicitar upload de comprovante de endereço,
                 Solicitar upload de relatório financeiro e Solicitar cadastro de conta bancária no onboarding — adicionam
                 etapas extras quando um lojista novo se cadastra. No fim da aba, o bloco Conta Admin mostra qual empresa
                 está ativa como conta administradora da plataforma.', ],

            // ---- adquirentes --------------------------------------------------------
            ['tutorial', 'cadastrar uma adquirente e ativar os métodos de pagamento',
                'adquirente, cadastrar adquirente, cadastro de adquirente, adquirente que contratei, plugar adquirente, integrar adquirente, adicionar adquirente, credenciais, api key, ativar pix, sandbox, webhook da adquirente',
                'Vá em White Label → Adquirentes e clique em Adicionar Adquirente. Em Dados Gerais preencha o Nome de
                 exibição e escolha a Classe de integração (ao escolher, a logo carrega e a janela mostra só os campos
                 daquela adquirente); informe a URL Base da API se ela te passou um endereço próprio e marque conforme o
                 contrato: Saque automático, É BaaS, Suporta subcontas, maquininha e Possui registro de webhook (neste,
                 cole também a Chave secreta do webhook). Em Credenciais da API preencha as chaves pedidas (App ID, Public
                 Key, Secret Key, Token — variam por adquirente). Em Configurações Específicas escolha o Ambiente (Produção
                 ou Sandbox) e a Chave PIX padrão quando pedido. Clique em Salvar: o card aparece com os métodos
                 DESLIGADOS. Para ativar, ligue a chave do método no card — a ativação é exclusiva, só uma adquirente
                 responde por cada método, e ligar numa desliga automaticamente na outra. Maquininha não tem chave: o card
                 só mostra Ativa/Inativa, porque o vínculo é por terminal. Por fim, clique em Copiar URL webhook e cadastre
                 essa URL no portal da adquirente — é por ela que a confirmação de pagamento chega.', ],

            ['tutorial', 'registrar o custo da adquirente (editar taxas da adquirente)',
                'taxa da adquirente, custo, editar taxas, quanto a adquirente cobra, custo por parcela',
                'Em White Label → Adquirentes, clique em Editar taxas no card da adquirente para abrir o painel Taxas da
                 Adquirente. Preencha por método a taxa fixa (R$) e a percentual (%): PIX, Boleto, Débito, Crypto e Saque.
                 No Cartão de crédito há a taxa fixa mais o bloco Taxas por parcela, com um percentual para cada
                 parcelamento de 1x a 12x. Há também a seção Pagamento de boleto: o custo cobrado quando um lojista paga um
                 boleto usando o saldo da conta. Clique em Confirmar; os valores valem para as próximas transações e
                 alimentam o cálculo de lucratividade. Lembre: estas taxas são o que a adquirente cobra de VOCÊ. O que o
                 lojista paga fica em Admin → Todas as contas → Alterar taxas.', ],

            ['tutorial', 'contratar uma nova adquirente (vitrine de adquirentes disponíveis)',
                'contratar adquirente, adquirentes disponíveis, parceiros, baas, trocar de adquirente, nova adquirente',
                'White Label → Adquirentes disponíveis é uma vitrine informativa dos parceiros já compatíveis com a
                 plataforma. Cada card traz logo, nome, site e etiquetas: Adquirente (processa pagamentos), BaaS (banco como
                 serviço, infraestrutura de conta e transferências) ou Info. Clicar no card abre o site oficial do parceiro
                 em nova aba — nada é ativado ou contratado por aqui. O caminho completo é: 1) descobrir e contratar no
                 portal da adquirente, onde você abre conta, passa pela análise e recebe as credenciais; 2) voltar em White
                 Label → Adquirentes e usar Adicionar Adquirente com essas credenciais; 3) ligar as chaves dos métodos e
                 preencher Editar taxas; 4) cadastrar a URL de webhook no portal dela.', ],

            // ---- contas / lojistas --------------------------------------------------
            ['tutorial', 'alterar as taxas que um lojista paga',
                'taxa do lojista, alterar taxas, mudar a taxa, mudar taxa, cobrar do lojista, taxa que o lojista paga, taxa por parcela, taxa pix, taxa boleto, taxa cartão',
                'Vá em Admin → Todas as contas, clique na linha da conta (ou em Gerenciar) para abrir Detalhes da conta e
                 clique no cartão Alterar taxas: abre a janela Taxas da Conta já com os valores atuais. Configure PIX e
                 Boleto com taxa fixa (R$) e variável (%); no Cartão, taxa fixa mais Taxas por parcela, com um percentual
                 para cada parcela de 1x a 12x; Débito, Cripto e Pagamento de boleto (taxa cobrada quando o lojista paga um
                 boleto de terceiros com o saldo) são opcionais. Clique em Confirmar — a mensagem Taxas atualizadas com
                 sucesso confirma e as próximas vendas já usam as taxas novas. Se deixar o Débito em branco, a plataforma
                 usa automaticamente a taxa de Cartão à vista (1x) daquela conta.', ],

            ['tutorial', 'escolher qual adquirente processa cada método de um lojista (roteamento)',
                'adquirente customizada, roteamento, adquirente por conta, mudar adquirente do lojista, baas',
                'Em Admin → Todas as contas, abra a conta e clique em Adquirentes customizadas: abre a janela Adquirentes e
                 Banking da conta. Para Cartão de crédito, Boleto, PIX e BaaS (saques), escolha Utilizar padrão (segue a
                 configuração geral da plataforma) ou uma adquirente específica só para esta conta. Em Métodos adicionais
                 aparecem outros serviços roteáveis, como recarga e pagamento de boleto; cada um só fica selecionável se
                 existir pelo menos uma adquirente compatível cadastrada — se aparecer Nenhuma adquirente compatível, é isso
                 que falta. Clique em Atualizar: as vendas daquela conta naquele método passam pela adquirente escolhida,
                 sem afetar as outras contas.', ],

            ['tutorial', 'definir reserva financeira de um lojista específico',
                'reserva financeira, reserva personalizada, retenção, segurar saldo, percentual de reserva',
                'Em Admin → Todas as contas, abra a conta e clique em Reserva financeira: abre a janela Reserva Financeira
                 Personalizada. Ligue a chave Reserva personalizada ativa para esta conta ter regra própria; desligada, ela
                 segue a reserva global das Configurações da plataforma (quando habilitada lá). Com a chave ligada, defina
                 por método (PIX, Boleto e Cartão) o Percentual de reserva (0 a 100%) e os Dias de retenção (1 a 365) — a
                 janela mostra ao lado o valor Atual de cada método para comparação. Clique em Salvar configuração: a partir
                 das próximas vendas o percentual fica retido pelo prazo escolhido antes de virar saldo disponível. Vendas
                 já feitas não são afetadas.', ],

            ['tutorial', 'bloquear, desbloquear uma conta e entrar como o lojista',
                'bloquear conta, desbloquear, suspender lojista, fazer login como, impersonar, ver como o lojista, notas internas',
                'Em Admin → Todas as contas, abra a conta. Para bloquear: clique em Bloquear e confirme na janela BLOQUEAR
                 CONTA — a conta fica marcada como Bloqueada e o lojista para de operar, mas nada é apagado (histórico e
                 saldo permanecem). O mesmo cartão vira Desbloquear e um clique reativa na hora. Use Fazer login para entrar
                 na conta do lojista e ver a plataforma exatamente como ele vê: sua sessão de admin fica guardada, para
                 voltar basta sair da conta dele. No painel também estão Notas internas (observações que só a equipe admin
                 vê), Agendar contato (lembrete de follow-up) e o botão do WhatsApp, que abre a conversa com uma mensagem
                 pronta de recuperação citando há quantos dias a loja está sem vender.', ],

            ['tutorial', 'encontrar uma conta e identificar lojista parado de vender',
                'buscar conta, procurar lojista, cnpj, score, última venda, parado, sem vender, radar',
                'Em Admin → Todas as contas, use o filtro de busca por nome, documento (CPF/CNPJ), ID ou e-mail, e o filtro
                 de tipo para segmentar. Alterne entre grade e lista no botão à direita (a plataforma lembra sua escolha).
                 Cada linha mostra Score, Status (Aprovada, Pendente, Em Análise, Bloqueada ou Rejeitada), Saldo disponível,
                 Última venda e Criado em. A cor da coluna Última venda é o radar de lojista esfriando: verde = vendeu nos
                 últimos 3 dias, amarelo = até 10 dias parada, vermelho = mais de 10 dias sem vender.', ],

            ['tutorial', 'analisar e aprovar uma solicitação de gateway',
                'solicitação de gateway, aprovar cadastro, aprovar lojista, aprovar um lojista, liberar lojista, reprovar, análise de documentos, fila de aprovação, lojista novo cadastrou',
                'Admin → Solicitações de Gateway lista só as contas que dependem de decisão sua (6 por página). Clique no
                 card para abrir a análise: revise os Dados da Conta (tipo, documento, razão social, intenções de uso),
                 confira os Documentos (contrato social, frente e verso do documento, selfie com documento, comprovante de
                 endereço, histórico de faturamento — todos podem ser baixados) e as Outras Informações (faturamento médio,
                 ticket médio, site, telefone, e-mail, produtos). Os telefones têm ícone do WhatsApp para falar com o
                 lojista antes de decidir. Precisa ajustar taxas ou permissões? Clique em Gerenciar conta ANTES, porque no
                 modal de resposta nada pode ser alterado. Depois clique em Responder solicitação: o passo 1 é só um resumo
                 do que a conta terá liberado; clique em Próximo e no passo 2 escolha Aprovado ou Reprovado e confirme.
                 Aprovado ativa a conta na hora; Reprovado desativa mas não apaga — a conta fica na fila com o selo
                 Rejeitado e pode ser aprovada depois se o lojista regularizar.', ],

            ['tutorial', 'trocar a senha de um usuário e bloquear o acesso dele',
                'senha, trocar senha, resetar senha, esqueci a senha, esqueceu a senha, mudar a senha, bloquear usuário, acesso, 2fa, usuario, usuarios',
                'Vá em Admin → Todos os usuários e clique na linha da pessoa para abrir Detalhes do Usuário. Para a senha:
                 clique em Alterar Senha, digite a Nova senha e repita em Confirmar nova senha — o botão só habilita quando
                 as duas ficam exatamente iguais — e confirme; a nova senha já vale no próximo login. A plataforma não envia
                 a senha por e-mail, então avise a pessoa por um canal seguro. Para o acesso: clique em Bloquear (usuário
                 ativo) ou Desbloquear e confirme; bloqueado, ele perde o acesso ao painel. Atenção: bloquear o USUÁRIO
                 corta só o acesso dele — para impedir a empresa de vender, use o bloqueio de conta em Todas as contas. O
                 2FA aparece na ficha apenas para leitura: quem liga e desliga é o próprio usuário nas configurações dele.', ],

            ['tutorial', 'usar o kanban de contas (CRM) para reativar lojista parado',
                'kanban, crm, reativação, churn, lojista parado, follow-up, quadro, pipeline, contas em risco',
                'Admin → Gerenciamento de contas tem quadros Kanban. O cabeçalho traz quatro cards clicáveis: Em Análise,
                 Aprovados Inativos (com pílulas Nunca venderam, +7, +14 e +30 dias), Aprovados Ativos (vendas nos últimos 7
                 dias) e Bloqueados. O quadro Status da Conta (automático) tem as colunas Em Análise, Aprovados e Bloqueados
                 — você NÃO arrasta cards aqui, a conta muda de coluna quando o status real muda em Todas as contas. O
                 quadro Monitoramento & Reativação (automático) tem Nunca venderam, +14 dias sem vender, +7 dias sem vender
                 e Ativos, classificando pela data da última venda: é o quadro de churn. Cada card mostra nome, documento,
                 score, vendas, saldo e a etiqueta da última venda, e traz o botão do WhatsApp (abre conversa com mensagem
                 pronta de reativação citando os dias parados), Notas internas e Agendar contato. Para um funil próprio,
                 clique em Novo, dê um nome e use Adicionar empresa nas colunas; em Colunas você renomeia, troca cor e
                 adiciona colunas. Rotina que funciona: abrir Monitoramento & Reativação, começar pela coluna +14 dias,
                 disparar o WhatsApp e usar Agendar contato para marcar o retorno.', ],

            // ---- transações e dinheiro ---------------------------------------------
            ['tutorial', 'encontrar uma transação e ler o detalhe dela',
                'transação, buscar venda, end to end, e2e, pix, referência externa, filtro, id da transação, comprovante',
                'Em Admin → Todas as transações use os filtros do topo: Transação, Adquirente, Cliente, Pagamento, Status e
                 Parcelas — preencha e clique em Aplicar. No filtro Transação você busca por ID da transação, Referência
                 externa (o ID do pedido no sistema do lojista) ou End-to-End ID (PIX), que é o identificador que o banco do
                 pagador informa em contestações. No filtro Cliente, busque por e-mail, nome, CPF/CNPJ ou telefone. Clique
                 na linha para abrir o detalhe: atenção, você entra DENTRO da conta do lojista (aparece Acessando
                 transação...), e para voltar use a opção de sair da conta acessada no topo. No detalhe estão valor, status,
                 ID, data, adquirente e meio de pagamento, os dados do cliente com atalho de WhatsApp, o carrinho, o código
                 PIX ou a linha digitável do boleto e o quadro Taxas (Valor Bruto, Taxa e Valor líquido). Ver QRCode abre o
                 QR em tela cheia e Copiar código Pix copia o copia-e-cola. Guarde o End-to-End ID: é ele que o banco pede
                 em disputas. Vendas de maquininha aparecem como Pagamento presencial, sem cliente e sem carrinho.', ],

            ['tutorial', 'reenviar o webhook de uma venda para o sistema do lojista',
                'reenviar webhook, não caiu no sistema, não caiu no sistema do lojista, venda não caiu, notificação da venda, reenviar, loja virtual, erp',
                'Quando o lojista disser que a venda foi paga mas não caiu no sistema dele: abra a transação em Admin →
                 Todas as transações e clique em Reenviar Webhook no detalhe. Isso reenvia a notificação da venda para o
                 sistema dele (loja virtual, ERP). O botão fica desabilitado enquanto a transação está pendente ou
                 aguardando pagamento, porque não há o que notificar antes da confirmação. Depois do envio, o sistema mostra
                 a resposta que o sistema do lojista devolveu: 200 OK em verde significa que recebeu; erro em vermelho
                 significa que o endereço de webhook dele está com problema — nesse caso o ajuste é do lado do lojista.', ],

            ['tutorial', 'estornar uma transação (total ou parcial)',
                'estorno, estornar, devolver dinheiro, reembolso, cancelar venda, chargeback',
                'No detalhe da transação, clique em Estornar — o botão só fica ativo para transações pagas ou autorizadas. O
                 modal mostra o Valor da transação, quanto foi Já estornado e o Disponível para estorno, e aceita valor
                 menor para estorno parcial. Escolha o Tipo de estorno: Estorno automático via API (a adquirente devolve o
                 dinheiro ao comprador) ou Estorno manual via conta bancária (registra internamente e sua equipe financeira
                 paga por fora). Selecione o Motivo (solicitação do cliente, pagamento duplicado, fraude detectada) e, se
                 quiser, uma descrição. Clique em Confirmar Estorno: o comprador recebe o valor de volta e a transação passa
                 a Estornado. A ação é irreversível.', ],

            ['tutorial', 'aprovar ou reprovar um saque (transferências)',
                'saque, transferência, aprovar saque, liberar saque, pediu saque, solicitou saque, reprovar, pix do lojista, pendente, falhou, limite de saque',
                'Em Admin → Transferências, abra o filtro Status e marque Pendente para ver o que aguarda você — só esse
                 status mostra os botões. Confira a conta, o destino (Chave PIX com a chave formatada ou Conta Bancária com
                 o nome do banco) e o valor. Aprovar libera o pagamento e o envio começa na adquirente; Reprovar nega e o
                 valor volta ao saldo do lojista. Para investigar antes, clique na linha (fora dos botões) e você entra na
                 conta do lojista no detalhe do saque. Status: Pendente (aguarda você), Aprovado/Processando (adquirente
                 executando), Pago/Concluído, Agendado, Reprovado e Falhou (a adquirente recusou, ex.: chave PIX inválida).
                 Se o lojista reclama que nem consegue SOLICITAR o saque, o problema costuma estar no Limite máximo por
                 saque e no Limite diário definidos em Configurações do White Label, ou na reserva financeira. Desconfie de
                 saque com destino diferente do titular: reprovar é seguro, o valor volta para o saldo dele.', ],

            ['tutorial', 'aprovar ou rejeitar uma antecipação de recebíveis',
                'antecipação, antecipar, recebíveis, adiantamento, taxa de antecipação, rejeitar',
                'Em Admin → Antecipações, filtre por Status Pendente. Confira o VALOR SOLICITADO e a TAXA: o lojista recebe
                 o solicitado menos a taxa. Clique em Aprovar e confirme — na hora o valor líquido sai do saldo em
                 antecipação e cai no saldo disponível dele, já sacável, e a diferença (a taxa) fica com a plataforma como
                 sua receita; ele recebe notificação e a linha passa a Aprovado com o VALOR APROVADO preenchido. Para negar,
                 clique em Rejeitar, escreva o Motivo da rejeição (o lojista lê exatamente esse texto) e confirme: o valor
                 volta na hora para o saldo a receber e as vendas ficam liberadas para um novo pedido. O sistema recalcula a
                 taxa no momento da aprovação usando a configuração atual da conta, então confira a taxa antes de aprovar
                 pedidos grandes.', ],

            ['tutorial', 'gerenciar assinaturas dos lojistas (cobrar, pausar, cancelar, editar)',
                'assinatura, recorrência, cobrança recorrente, cobrar agora, pausar, cancelar, atrasada, checkout',
                'Admin → Todas as assinaturas mostra cliente, conta, plano com recorrência e valor, métodos aceitos, status
                 e a data da próxima cobrança. Status possíveis: Ativa, Atrasada (a última cobrança não foi paga), Pausada,
                 Cancelada e Concluída. No botão de ações (⋯) da linha abre o modal com quatro opções: Ver detalhes, Cobrar
                 agora (dispara o ciclo na hora, sem esperar a data — no Boleto e no Pix o cliente recebe o link
                 imediatamente), Pausar (congela as cobranças futuras; vira Retomar) e Cancelar (encerra em definitivo, sem
                 reativação). Cuidado: clicar na linha fora do ⋯ faz você entrar na conta do lojista. No detalhe dá para
                 editar o Valor da assinatura e os Métodos de pagamento (é obrigatório deixar pelo menos um marcado) e
                 personalizar o checkout com descontos por método, redirecionamento pós-pagamento, avaliações e escassez —
                 nada vale sem clicar em Salvar configurações de checkout. Se a intenção é pausar temporariamente, use
                 Pausar: Cancelar é irreversível.', ],

            ['tutorial', 'empréstimos: aprovar, recusar e definir as regras',
                'empréstimo, crédito, juros, parcela, carteira, oferta, limite, atraso',
                'Admin → Empréstimos traz cinco indicadores: Na rua (emprestado que ainda não voltou), Já voltou, Lucro
                 realizado (juros efetivamente recebidos), Em atraso (com o número de contratos) e Parcelas do mês (com
                 quanto já entrou). Em Pedidos pendentes, analise Valor, Meses, Parcela e Total, e use as três colunas de
                 risco: Média vendas 3m (o melhor termômetro de capacidade de pagamento), Saldo atual e Tempo de casa.
                 Aprovar credita o valor NA HORA na carteira do lojista e cria um contrato ativo. Recusar exige escrever o
                 Motivo da recusa — o lojista recebe exatamente esse texto. Nos Contratos você acompanha Emprestado, Já
                 voltou, Próxima parcela e a Situação (Em dia, Parcial ou Atrasado, com os dias de atraso). Em
                 Configurações, defina os padrões da plataforma: Juros ao mês (%), Máx. de meses, Valor mínimo (R$) e
                 Bloquear saque após (dias de atraso). Para condição diferente de um lojista, vá em Gestão de contas, abra o
                 card da empresa e clique em Empréstimos: ali define Limite, juros e prazo próprios, e pode criar uma Oferta
                 direta informando o valor.', ],

            ['tutorial', 'lançar a operação de maquininhas (do zero à venda presencial)',
                'lançar maquininha, vender maquininha, começar a vender maquininha, oferecer maquininha, venda presencial, terminal',
                'ETAPA 1 (habilitar): em White Label → Configurações → aba Geral, ligue Ativar maquininha de cartão, defina o
                 Limite de maquininhas por pedido (1 a 10) e salve. ETAPA 2 (vitrine): em Admin → Modelos de maquininha,
                 clique em Cadastrar modelo, envie a foto, preencha nome, descrição e preço com o frete embutido e deixe
                 Ativo ligado. ETAPA 3 (pedidos): quando o lojista comprar, o pedido cai em Admin → Solicitações de
                 maquininha; aprove o pedido pendente e, em Registrar envio, informe o serial de cada maquininha e a
                 transportadora (o rastreio é opcional; a adquirente só é pedida quando há mais de uma capaz) — é o serial
                 informado aqui que cria o vínculo do terminal. Na entrega, use Marcar entregue. ETAPA 4 (terminais): em
                 Admin → Todas as maquininhas os terminais já aparecem vinculados ao lojista (filtre por serial, lojista ou
                 adquirente) e você pode ativar, desativar ou remover cada um; Cadastrar maquininha serve para lançar um
                 terminal avulso. As vendas daquele terminal passam a ser creditadas ao lojista e aparecem em Todas as
                 transações como Pagamento presencial.', ],

            // ---- maquininhas --------------------------------------------------------
            ['tutorial', 'cadastrar modelo de maquininha na vitrine',
                'modelo de maquininha, cadastrar modelo, vitrine, vender maquininha, começar a vender maquininha, oferecer maquininha, foto, preço, frete, desativar modelo',
                'Vá em Admin → Modelos de maquininha e clique em Cadastrar modelo. Clique no quadrado de Foto para enviar a
                 imagem (PNG, JPG ou WEBP até 5MB) — é ela que aparece na vitrine do lojista. Preencha o Nome (obrigatório)
                 e a Descrição, e informe o Preço em reais com o frete JÁ EMBUTIDO, porque a plataforma não calcula frete
                 por endereço. Deixe a chave Ativo ligada e clique em Cadastrar modelo. O lojista vê a vitrine como um botão
                 de maquininha na carteira dele, listando só os modelos Ativos; ao comprar, ele escolhe a quantidade
                 (limitada pelo Limite de maquininhas por pedido em White Label → Geral), informa o endereço e o valor é
                 debitado do saldo dele na hora. Para tirar de linha, prefira Desativar (sai da vitrine e o cadastro fica
                 guardado) em vez de Remover.', ],

            ['tutorial', 'atender pedido de maquininha: aprovar, registrar envio e entregar',
                'pedido de maquininha, solicitação de maquininha, serial, transportadora, rastreio, entregue, recusar pedido',
                'Admin → Solicitações de maquininha abre já filtrada em Pendentes. O valor do pedido já foi debitado do
                 saldo do lojista na compra, então esta tela cuida só da logística. Clique na linha para ver endereço de
                 entrega e observação antes de decidir. Aprovar muda o status para Aprovado e o pedido passa a esperar
                 despacho. Recusar exige o Motivo da recusa (obrigatório, o lojista lê esse texto) e devolve o valor ao
                 saldo dele. Quando despachar, clique em Registrar envio: digite o serial de cada maquininha (um campo por
                 unidade, todos obrigatórios e sem repetir), preencha Transportadora / forma de envio (obrigatório) e o
                 Código de rastreio (opcional); se aparecer Adquirente do vínculo, escolha em qual adquirente os terminais
                 serão vinculados (o campo só aparece quando há mais de uma compatível). Ao confirmar, o status vira
                 Enviado, o lojista é notificado e as maquininhas são vinculadas a ele automaticamente. Cancelar só existe
                 enquanto o pedido está Aprovado. Na entrega, clique em Marcar entregue.', ],

            ['tutorial', 'vincular um terminal (maquininha) ao lojista certo',
                'terminal, serial, vincular maquininha, venda presencial não caiu, ativar terminal, desativar, remover',
                'Em Admin → Todas as maquininhas, clique em Cadastrar maquininha. Selecione o Lojista que vai receber as
                 vendas, a Adquirente por onde o terminal processa (a lista mostra só adquirentes com suporte a maquininha)
                 e digite o Serial do terminal exatamente como ele é — é por esse código que cada venda encontra o lojista
                 certo, e um erro de digitação faz a venda não ser creditada. Clique em Cadastrar; o lojista continua
                 selecionado para você cadastrar vários em sequência. Para achar depois, use os filtros Serial (aceita um
                 trecho), Lojista e Adquirente. Desativar faz o terminal parar de creditar vendas mas preserva o cadastro
                 (ideal para pausa ou manutenção); Remover apaga o vínculo, e vendas naquele aparelho deixam de encontrar
                 dono — use só quando o terminal for devolvido ou trocado. Dá para gerenciar tudo também pelo cartão
                 Terminais (maquininha) dentro do detalhe da conta, já filtrado por aquele lojista.', ],

            // ---- relatórios ---------------------------------------------------------
            ['tutorial', 'ler o dashboard administrativo e filtrar os números',
                'dashboard, volume total, ticket médio, saldo, recebíveis, filtro, período, gráfico, novos usuários',
                'Admin → Dashboard abre sempre com os últimos 7 dias. No topo direito, o seletor de Empresas mostra uma
                 conta específica ou Total (Todas as contas) — marque e clique em Aplicar DENTRO do seletor, senão nada
                 muda; o mesmo vale para o seletor de Adquirentes (Limpar remove todos os filtros). Os filtros se combinam.
                 Indicadores: VOLUME TOTAL (tudo processado, pago ou não), VALOR TOTAL PAGO (o que virou dinheiro para os
                 lojistas), TICKET MÉDIO e TRANSAÇÕES PAGAS. Abaixo vêm os saldos somados: Disponível (livre para saque),
                 Reserva (retido como reserva), Bloqueado (disputas) e Recebíveis (total a receber). O card Faturamento do
                 White Label é o SEU dinheiro: receita e custo de adquirência, banking, antecipação, antifraude e as
                 Cobranças Bancalize (TPV), já descontadas do Lucro Total. Em Status das Contas, se aparecerem Contas
                 Pendentes há lojistas esperando aprovação em Solicitações de Gateway. O dashboard é somente leitura.', ],

            ['tutorial', 'relatórios de faturamento por conta e por adquirente (exportar)',
                'relatório, faturamento, exportar, csv, planilha, por conta, por adquirente, fechamento de mês, volume',
                'Admin → Faturamento por conta mostra quanto cada lojista faturou por método (Cartão, PIX, Boleto) com valor
                 e quantidade, mais a coluna Total. Atenção: essa tela mostra sempre o acumulado de TODO o histórico — o
                 seletor de datas nela não altera os totais, e o Exportar baixa só as contas da página aberta (até 10). Para
                 fechar um mês, use a tela irmã Faturamento: lá o filtro Conta (ID, Razão Social, CPF/CNPJ ou e-mail) e o
                 período funcionam de verdade, e o Exportar baixa TODOS os registros do filtro. Admin → Faturamento por
                 Adquirente mostra o volume bruto processado por cada adquirente no período, por método, com TOTAL BRUTO; o
                 CSV dele traz todos os adquirentes do filtro de uma vez. Rotina de fechamento: abra Faturamento, selecione
                 o mês, Aplicar e Exportar.', ],

            ['tutorial', 'ver a margem real por lojista (lucratividade)',
                'lucratividade, margem, lucro por conta, quanto lucro, quanto estou lucrando, lucrando, quanto ganho, comissão, custo, renegociar taxa',
                'Admin → Lucratividade mostra o que você realmente ganha por conta: Comissão (o que você cobrou dos
                 lojistas) menos Custo (o que as adquirentes cobraram de você) = Lucro, com a Margem em percentual sobre o
                 volume. Os cards do topo resumem o período: Total de Contas, Lucro Total, Total de Comissões, Custo Total,
                 Volume Total e Período. Na tabela, lucro aparece em verde e custo em vermelho. Clique na linha da conta
                 para abrir Detalhes de Lucros, com quebra Por Método de Pagamento e as Transações Recentes. Exportar baixa
                 o CSV de todas as contas do período. Procure contas com VOLUME alto e MARGEM baixa: são as candidatas a
                 renegociação — ou você aumenta a taxa do lojista, ou reduz o custo trocando a adquirente que processa
                 aquela conta.', ],

            ['tutorial', 'entender a sua fatura da plataforma (o que você paga)',
                'fatura, minha fatura, cobrança, ciclo, vencida, pendente, quanto pago, boleto da plataforma',
                'White Label → Faturamento é o extrato do SEU contrato com a plataforma, não o faturamento dos lojistas. Os
                 três cards do topo: Fatura atual (valor acumulado do ciclo em andamento, que cresce conforme suas vendas
                 são aprovadas), Total pago (todas as faturas já quitadas) e Pendente (faturas fechadas e vencidas
                 aguardando pagamento). Abaixo, três cards mostram quanto da fatura atual veio de Cartão, PIX e Boleto, com
                 o Volume de vendas correspondente. Na tabela Todas as faturas: número, Período, Valor e Status (Em
                 andamento, Fechada, Paga, Vencida ou Cancelada). O ciclo pode ser diário, semanal (fecha de sexta a sexta)
                 ou mensal conforme o contrato; ao fechar, o vencimento é 7 dias depois e você recebe um e-mail de cobrança.
                 O pagamento não é feito por essa tela. Se o card Pendente não zera após pagar, confirme com o suporte
                 usando o número da fatura.', ],

            // ---- comunicação e integrações -----------------------------------------
            ['tutorial', 'personalizar os e-mails automáticos da plataforma',
                'e-mail personalizado, email automático, conta criada, conta bloqueada, solicitação aprovada, recusada, variáveis',
                'Em White Label → E-mails personalizados você personaliza quatro momentos: Conta criada, Conta bloqueada,
                 Solicitação aprovada e Solicitação recusada. Clique em Personalizar (ou Editar) no card do evento, escreva
                 o Assunto e monte o Corpo no editor, e salve — o card passa a exibir o selo Personalizado. Use as
                 variáveis dinâmicas exatamente com as chaves: {name} vira o nome do destinatário, {company} o nome da conta
                 do lojista e {platform} o nome da sua plataforma. Ex.: Olá {name}, sua conta na {platform} foi aprovada.
                 Para voltar ao texto padrão, clique em Remover no card e confirme. Configure o SMTP em Configurações → aba
                 Outros antes de personalizar, senão o e-mail sai com o remetente padrão em vez do seu domínio.', ],

            ['tutorial', 'criar os termos de uso que o cliente precisa aceitar',
                'termos de uso, aceite, contrato, popup, regras',
                'Em White Label → Termos de Uso, clique em Criar termos de uso (ou Editar termos) e escreva o conteúdo no
                 editor de texto rico — o mínimo é 10 caracteres, texto muito curto dá erro ao salvar. Ao salvar, os termos
                 valem imediatamente: enquanto houver termos ativos, o cliente vê um popup de aceite antes de acessar
                 qualquer página e, sem aceitar, não consegue usar a plataforma. Use Visualizar como cliente para abrir a
                 página /termos em nova aba exatamente como ele vê, antes de divulgar. Remover termos exclui os termos
                 ativos (com confirmação) e o popup deixa de aparecer.', ],

            ['tutorial', 'criar webhooks da plataforma para o sistema do cliente',
                'webhook, integração, erp, notificação automática, evento, amostragem, url',
                'Em White Label → Webhooks, clique em Criar webhook, informe a URL do sistema que vai receber as
                 notificações, escolha o evento Transação atualizada/criada e deixe a chave Ativo ligada. A partir daí, cada
                 ocorrência do evento dispara uma chamada para essa URL. Nas ações da linha dá para editar a URL,
                 ativar/desativar ou excluir. Em Configurações ficam as regras globais: Webhook de recebimento (ligar/
                 desligar e a Taxa de amostragem, de 1% a 100% das transações pagas) e os webhooks de chargeback, de
                 reembolso e de bloqueio cautelar, cada um independente. Comece com a amostragem baixa para validar a
                 integração e só depois suba para 100% em produção.', ],

            ['tutorial', 'onde fica a chave de API admin e como usá-la com segurança',
                'chave de api, api key, credencial, integração, token, rotacionar, segredo, vazamento',
                'A tela Configurações administrativas guarda a Chave Secreta que autentica as integrações nos endpoints
                 admin. A chave vem sempre mascarada: clique em REVELAR CHAVE (ícone de olho) para vê-la e em Copiar para
                 levá-la para a configuração da integração (o botão confirma com Copiado!); ao terminar, clique em ESCONDER.
                 Trate como senha: quem tem a chave opera como administrador. Nunca envie por WhatsApp, e-mail ou chat sem
                 criptografia, nunca coloque em código público, no frontend ou em repositórios, e compartilhe só com o
                 desenvolvedor responsável. O botão Rotacionar Chave hoje funciona como atalho de conferência (revela o
                 valor atual); a troca efetiva por uma chave nova é feita pelo suporte da plataforma. Depois de qualquer
                 troca, atualize a chave em TODAS as integrações, porque a antiga para de autenticar.', ],

            ['tutorial', 'configurar o bot de WhatsApp que atende os lojistas',
                'bot whatsapp, atendimento automático, meta business api, evolution, qr code, ia, openai, gemini, limite de saque',
                'Em WhatsApp → Configurações do bot, ligue a chave Habilitar Bot de WhatsApp e escolha o provedor. Meta
                 Business API: preencha Phone Number ID, WABA ID, Access Token e App Secret e cadastre no painel da Meta a
                 URL de webhook e o Verify Token mostrados na tela. Evolution GO: clique em Conectar via QR e escaneie o QR
                 Code com o WhatsApp do número que vai atender (se o QR sumir antes, gere outro). Acompanhe o status
                 Desconectado / Aguardando QR Code / Conectado. Depois escolha o provedor de IA (OpenAI ou Gemini), informe
                 a API Key e o modelo. Em seguida marque o que o bot pode fazer: consultar saldo, realizar saque, ver
                 extrato, criar link de pagamento, status de assinatura e status de transação, e defina o Limite de saque
                 sem confirmação — acima desse valor o saque exige confirmação humana. Clique em Salvar configurações.
                 Comece com um limite baixo e só aumente depois de confiar no comportamento do bot.', ],

            ['tutorial', 'acompanhar o bot de WhatsApp (painel de conversas e ações)',
                'painel do bot, conversas do bot, ações executadas, taxa de sucesso, saques via bot',
                'Em WhatsApp → painel do bot, os indicadores do topo mostram Total de Conversas, Ações Executadas, Saques via
                 Bot (valor total sacado pelo bot) e Taxa de Sucesso (percentual de ações concluídas com êxito). Na aba
                 Conversas, busque por conta ou número: a tabela traz Conta, Número, Status (Ativo/Aguardando) e a última
                 atualização, e o botão Ver abre o detalhe com as ações executadas naquela conversa. Na aba Ações
                 Executadas, filtre por status e por tipo (Saldo, Saque, Extrato e Link de pagamento — consultas de
                 Assinatura e Transação aparecem na lista mesmo sem estarem no filtro); cada linha mostra a conta, o tipo, a
                 mensagem ou o áudio transcrito e o status (Executado, Cancelado, Falhou, Aguardando, Em análise). Se a Taxa
                 de Sucesso cair, abra as ações com status Falhou para entender o que travou.', ],

            ['tutorial', 'publicar um aviso para os lojistas dentro da plataforma',
                'aviso, comunicado, avisos para sellers, banner, manutenção, novidade, campanha',
                'Em White Label → Avisos para Sellers, clique em Novo aviso, preencha Título e Mensagem e, se quiser, envie
                 uma imagem (há pré-visualização). Defina o período com Exibir a partir de e Exibir até, deixe a chave Aviso
                 ativo ligada e salve. Na lista, dá para ativar/desativar direto na linha sem abrir a edição, clicar na
                 linha para editar ou excluir definitivamente (com confirmação). Use o período de exibição para programar
                 campanhas com antecedência: o aviso entra e sai do ar sozinho nas datas definidas, sem você precisar
                 lembrar de tirá-lo.', ],

            ['tutorial', 'contratar antifraude',
                'antifraude, fraude, proteção, golpe, chargeback, segurança',
                'White Label → Antifraude é uma tela de consulta, com as colunas Provedor, Tipo, Status e Criado em. Ela é
                 somente leitura: não há botão de adicionar, editar ou remover, e enquanto não houver solução contratada
                 aparece Não há soluções de antifraude cadastradas. Para contratar, fale com o suporte da plataforma: ele
                 apresenta os provedores disponíveis, alinha custos e faz toda a configuração técnica — não há credencial
                 nem formulário para preencher nessa tela. Depois de configurada, a proteção atua automaticamente no
                 processamento. Boa parte das adquirentes já aplica a própria análise de risco; a solução dessa tela é uma
                 camada adicional. Antes de contratar, levante volume, recusas e estornos em Todas as transações: com esses
                 números o suporte indica o provedor mais adequado ao seu perfil de risco.', ],
        ];
    }
}
