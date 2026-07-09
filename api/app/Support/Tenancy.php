<?php

namespace App\Support;

/**
 * Contexto de tenancy da requisição/processo.
 *
 * Guarda a "empresa atual". Quando há uma empresa vinculada, o CompanyScope
 * filtra automaticamente todas as consultas dos models de domínio e o trait
 * BelongsToCompany carimba o company_id em novos registros.
 *
 * Quando NÃO há empresa vinculada (agendador, webhook antes de resolver a
 * instância, comandos de console) o escopo fica inerte — as consultas enxergam
 * todas as empresas. Por isso jobs globais devem resolver a empresa por linha
 * (via wa_account/conversation) e, quando precisarem criar dados, rodar dentro
 * de Tenancy::run($companyId, fn () => ...).
 *
 * Registrado como singleton no container (ver AppServiceProvider), então vive
 * pelo tempo do processo — em QUEUE=sync isso é uma request/console por vez.
 */
class Tenancy
{
    private ?int $companyId = null;

    /** Empresa atual (ou null se nenhuma vinculada). */
    public function id(): ?int
    {
        return $this->companyId;
    }

    /** Há uma empresa vinculada? (Se sim, o escopo filtra.) */
    public function check(): bool
    {
        return $this->companyId !== null;
    }

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function forget(): void
    {
        $this->companyId = null;
    }

    /**
     * Executa $callback com a empresa $companyId vinculada, restaurando o
     * contexto anterior ao final (mesmo em caso de exceção). Usado pelo webhook
     * e por jobs que precisam criar/consultar dados de uma empresa específica.
     */
    public function run(int $companyId, callable $callback)
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
