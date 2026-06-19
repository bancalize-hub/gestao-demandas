<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Lê os "Read Meeting Report" que o read.ai envia por e-mail após cada reunião e
 * extrai um resumo legível (visão geral + pontos discutidos + próximos passos).
 * Casa o relatório com a reunião pelo horário (e pela hora no assunto, quando dá).
 */
class GmailService
{
    public function __construct(private GoogleCalendarService $google) {}

    private const READAI_SENDER = 'executiveassistant@e.read.ai';

    /**
     * Procura o relatório do read.ai correspondente a uma reunião.
     *
     * @return array{subject:string, summary:string, received_at:Carbon}|null
     */
    public function findReadAiReport(User $user, Carbon $start, Carbon $end): ?array
    {
        $gmail = $this->google->gmail($user);

        // O relatório chega logo após a reunião; busca a partir do dia do início.
        $after = $start->copy()->subDay()->format('Y/m/d');
        $query = 'from:'.self::READAI_SENDER.' "Meeting Report" after:'.$after;

        $list = $gmail->users_messages->listUsersMessages('me', ['q' => $query, 'maxResults' => 15]);
        $msgs = $list->getMessages() ?? [];
        if (! $msgs) {
            return null;
        }

        // Hora marcada no formato que o read.ai usa no assunto (ex.: "2:00 PM").
        $subjectHour = $start->format('g:i A');

        $best = null;
        $bestScore = -1;
        foreach ($msgs as $m) {
            $full = $gmail->users_messages->get('me', $m->getId(), ['format' => 'full']);
            $received = Carbon::createFromTimestampMs((int) $full->getInternalDate());

            // O relatório vem DEPOIS da reunião começar e até ~24h depois.
            if ($received->lt($start) || $received->gt($end->copy()->addHours(24))) {
                continue;
            }

            $subject = $this->header($full, 'Subject');
            // Pontuação: bate a hora no assunto vale muito; senão, prioriza o mais próximo do fim.
            $score = 0;
            if (str_contains($subject, $subjectHour)) {
                $score += 100000;
            }
            $score -= abs($received->diffInSeconds($end)); // mais perto do fim = melhor

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['msg' => $full, 'subject' => $subject, 'received' => $received];
            }
        }

        if (! $best) {
            return null;
        }

        $body = $this->plainText($best['msg']);
        $summary = $this->buildSummary($body);
        if ($summary === '') {
            return null;
        }

        return [
            'subject' => $best['subject'],
            'summary' => $summary,
            'received_at' => $best['received'],
        ];
    }

    private function header(\Google\Service\Gmail\Message $msg, string $name): string
    {
        foreach ($msg->getPayload()?->getHeaders() ?? [] as $h) {
            if (strcasecmp($h->getName(), $name) === 0) {
                return (string) $h->getValue();
            }
        }

        return '';
    }

    /** Texto puro do e-mail (procura recursivamente a parte text/plain; cai pro html limpo). */
    private function plainText(\Google\Service\Gmail\Message $msg): string
    {
        $payload = $msg->getPayload();
        if (! $payload) {
            return '';
        }
        $plain = $this->findPart($payload, 'text/plain');
        if ($plain !== '') {
            return $plain;
        }
        $html = $this->findPart($payload, 'text/html');

        return $html !== '' ? trim(html_entity_decode(strip_tags($html))) : '';
    }

    private function findPart(\Google\Service\Gmail\MessagePart $part, string $mime): string
    {
        if (($part->getMimeType() ?? '') === $mime) {
            $data = $part->getBody()?->getData();
            if ($data) {
                return (string) base64_decode(strtr($data, '-_', '+/'));
            }
        }
        foreach ($part->getParts() ?? [] as $sub) {
            $found = $this->findPart($sub, $mime);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    /**
     * Monta um resumo legível a partir do corpo do relatório do read.ai:
     * visão geral (1º parágrafo) + pontos discutidos (chapters/topics) + próximos passos (action_items).
     */
    private function buildSummary(string $body): string
    {
        $body = str_replace(["\xCD\x8F", "\xE2\x80\x8C", "\xC2\xAD"], '', $body); // tira chars invisíveis
        // Remove URLs longas (sendgrid) para não poluir.
        $clean = preg_replace('#https?://\S+#', '', $body);

        $parts = [];

        // 1) Visão geral: tudo antes do bloco estruturado / do aviso de upgrade.
        $cut = $clean;
        foreach (['🔒', 'map[', 'Upgrade to view'] as $marker) {
            $pos = mb_strpos($cut, $marker);
            if ($pos !== false) {
                $cut = mb_substr($cut, 0, $pos);
            }
        }
        $overview = trim(preg_replace('/\n{2,}/', "\n", $cut));
        // Pega só o(s) primeiro(s) parágrafo(s) reais (descarta linhas curtas tipo título/data).
        $lines = array_values(array_filter(array_map('trim', explode("\n", $overview)), fn ($l) => mb_strlen($l) > 40));
        $overview = $lines ? implode("\n\n", array_slice($lines, 0, 2)) : '';

        // 2) Pontos discutidos: chapter + topics do bloco map[...].
        $chapters = [];
        if (preg_match_all('/chapter:(.*?)\s+topics:\[(.*?)\]\]/s', $clean, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $c) {
                $title = trim($c[1]);
                $topics = trim($c[2]);
                if ($title === '') {
                    continue;
                }
                $chapters[] = $topics !== '' ? "• {$title}: {$topics}" : "• {$title}";
            }
        }

        // 3) Próximos passos: action_items.
        $actions = '';
        if (preg_match('/action_items:\[(.*?)\]/s', $clean, $am)) {
            $actions = trim($am[1]);
        }

        if ($overview !== '') {
            $parts[] = $overview;
        }
        if ($chapters) {
            $parts[] = "Pontos discutidos:\n".implode("\n", array_slice($chapters, 0, 12));
        }
        if ($actions !== '') {
            $parts[] = "Próximos passos:\n{$actions}";
        }

        return trim(implode("\n\n", $parts));
    }
}
