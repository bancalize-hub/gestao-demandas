<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Trava de dono do OAuth: só entra a agenda do PRÓPRIO e-mail de quem conecta.
 *
 * Existe porque conectar a conta errada é um erro silencioso e caro — em 21/09/2026 um
 * login `pauloguilherme…@gmail.com` ficou com a agenda `bancalize@gmail.com` pendurada,
 * e a partir daí a IA oferece horário, cria evento, grava e transcreve dentro da agenda
 * de um terceiro. O teste existe para essa trava não cair sem ninguém perceber.
 */
class GoogleContaPropriaTest extends TestCase
{
    use RefreshDatabase;

    /** Callback com o e-mail que o Google devolveria, e o usuário que consentiu. */
    private function conectar(User $user, ?string $emailDoGoogle)
    {
        $this->mock(GoogleCalendarService::class, function ($mock) use ($emailDoGoogle) {
            $mock->shouldReceive('exchangeCode')->andReturn([
                'access_token' => 'at-teste',
                'refresh_token' => 'rt-teste',
                'expires_at' => now()->addHour(),
                'email' => $emailDoGoogle,
            ]);
            // Recusa tem de DEVOLVER o consentimento; o aceite nunca revoga.
            $mock->shouldReceive('revokeToken')->andReturnNull();
        });

        $state = Crypt::encryptString($user->id.'|'.now()->timestamp);

        return $this->get('/api/google/callback?code=qualquer&state='.urlencode($state));
    }

    private function usuario(string $email): User
    {
        $company = Company::create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste-'.uniqid(), 'is_active' => true]);

        return User::create([
            'name' => 'Fulano',
            'email' => $email,
            'password' => bcrypt('segredo123'),
            'company_id' => $company->id,
        ]);
    }

    public function test_conta_de_outro_email_e_recusada(): void
    {
        $user = $this->usuario('paulo@bancalize.com.br');

        $this->conectar($user, 'outra.pessoa@gmail.com')
            ->assertRedirectContains('google=email-diferente');

        // Nada pode ter sido gravado: a conexão anterior (aqui, nenhuma) segue intacta.
        $this->assertNull($user->fresh()->google_refresh_token);
        $this->assertNull($user->fresh()->google_email);
    }

    public function test_conta_do_proprio_email_e_aceita(): void
    {
        $user = $this->usuario('paulo@bancalize.com.br');

        $this->conectar($user, 'paulo@bancalize.com.br')
            ->assertRedirectContains('google=conectado');

        $this->assertSame('rt-teste', $user->fresh()->google_refresh_token);
        $this->assertSame('paulo@bancalize.com.br', $user->fresh()->google_email);
    }

    /** No Gmail ponto é ignorado e `+etiqueta` é apelido: mesma conta, não pode barrar o dono. */
    public function test_gmail_com_ponto_e_etiqueta_conta_como_o_mesmo_email(): void
    {
        $user = $this->usuario('p.silva+crm@gmail.com');

        $this->conectar($user, 'psilva@gmail.com')
            ->assertRedirectContains('google=conectado');

        $this->assertSame('rt-teste', $user->fresh()->google_refresh_token);
    }

    /** Fora do Gmail o ponto distingue caixas de verdade — ali a regra NÃO vale. */
    public function test_ponto_fora_do_gmail_continua_sendo_outra_conta(): void
    {
        $user = $this->usuario('p.silva@bancalize.com.br');

        $this->conectar($user, 'psilva@bancalize.com.br')
            ->assertRedirectContains('google=email-diferente');

        $this->assertNull($user->fresh()->google_refresh_token);
    }

    /** Sem e-mail no id_token não dá para provar que é o dono: recusa. */
    public function test_sem_email_no_id_token_recusa(): void
    {
        $user = $this->usuario('paulo@bancalize.com.br');

        $this->conectar($user, null)
            ->assertRedirectContains('google=email-diferente');

        $this->assertNull($user->fresh()->google_refresh_token);
    }
}
