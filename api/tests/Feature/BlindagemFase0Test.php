<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Travas de segurança da Fase 0. Cada teste aqui existe porque o buraco correspondente
 * estava ABERTO em produção em 21/09/2026 — e um teste é o que impede a próxima pessoa
 * de reabrir sem perceber.
 */
class BlindagemFase0Test extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = 'dono@teste.com'): User
    {
        $co = Company::create(['name' => 'Teste', 'slug' => Str::slug('teste-'.Str::random(5)), 'is_active' => true]);
        $u = User::create(['name' => 'Dono', 'email' => $email, 'password' => bcrypt('x'), 'company_id' => $co->id]);
        $u->is_admin = true;
        $u->save();

        return $u;
    }

    private function pngTemporario(): string
    {
        $png = tempnam(sys_get_temp_dir(), 'png');
        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $png);
        imagedestroy($im);

        return $png;
    }

    /**
     * Nome com extensão executável é recusado na porta.
     *
     * Esta trava é do próprio Laravel (`shouldBlockPhpUpload`, dentro da regra `mimes`) —
     * o teste existe para que ela não seja perdida numa troca de regra de validação, que
     * é o tipo de mudança que ninguém relaciona com segurança na hora de fazer.
     */
    public function test_logo_com_nome_php_e_recusado(): void
    {
        Sanctum::actingAs($this->admin());

        $this->post('/api/branding/logo', [
            'variant' => 'light',
            'logo' => new UploadedFile($this->pngTemporario(), 'inofensivo.php', 'image/png', null, true),
        ])->assertStatus(422);
    }

    /**
     * A extensão gravada vem do CONTEÚDO, não do nome enviado.
     *
     * Era aqui que morava o risco de verdade: o arquivo ia para o disco com a extensão que
     * o cliente escolheu. Um PNG chamado `.webp` é inofensivo; a mesma linha de código
     * aceitaria qualquer outra extensão que passasse pela validação.
     */
    public function test_extensao_gravada_vem_do_conteudo(): void
    {
        $user = $this->admin('conteudo@teste.com');
        Sanctum::actingAs($user);

        $this->post('/api/branding/logo', [
            'variant' => 'light',
            'logo' => new UploadedFile($this->pngTemporario(), 'foto.webp', 'image/png', null, true),
        ])->assertOk();

        $this->assertStringEndsWith('.png', (string) $user->company->fresh()->logo_light);
    }

    /** SVG é XML com <script> dentro e seria servido do NOSSO domínio: fora da lista. */
    public function test_svg_nao_e_aceito_como_logo(): void
    {
        Sanctum::actingAs($this->admin('svg@teste.com'));

        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->post('/api/branding/logo', [
            'variant' => 'light',
            'logo' => new UploadedFile($svg, 'logo.svg', 'image/svg+xml', null, true),
        ])->assertStatus(422);
    }

    /** Importar histórico cria conversa sem número vinculado: é ato de administrador. */
    public function test_import_txt_exige_administrador(): void
    {
        $user = $this->admin('comum@teste.com');
        $user->is_admin = false;
        $user->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/wpp/import-txt', [
            'text' => '01/01/2026 10:00 - Fulano: oi',
            'number' => '5511999999999',
        ])->assertForbidden();
    }

    /** A sessão do agente só abre dentro da aplicação — não em /etc nem em outro projeto. */
    public function test_agente_recusa_diretorio_fora_da_aplicacao(): void
    {
        $user = $this->admin('super@teste.com');
        $user->is_super_admin = true;
        $user->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/agent/sessions', ['cwd' => '/etc'])
            ->assertStatus(422);

        $this->postJson('/api/agent/sessions', ['cwd' => '/var/www/gestao'])
            ->assertSuccessful();
    }

    /** Força bruta de senha: o sexto palpite do mesmo IP não é respondido. */
    public function test_login_trava_depois_de_cinco_tentativas(): void
    {
        RateLimiter::clear('login');
        User::factory()->count(0)->create();
        $this->admin('alvo@teste.com');

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/login', ['email' => 'alvo@teste.com', 'password' => 'errada'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'alvo@teste.com', 'password' => 'errada'])
            ->assertStatus(429);
    }

    /** O portal público não aceita texto sem tamanho: era gravar megabyte no banco de graça. */
    public function test_solicitacao_publica_tem_teto_de_tamanho(): void
    {
        $co = Company::create(['name' => 'Portal', 'slug' => 'portal-'.Str::random(5), 'is_active' => true]);

        $this->postJson('/api/solicitacoes', [
            'company' => $co->slug,
            'title' => str_repeat('a', 201),
            'description' => 'teste',
        ])->assertStatus(422);
    }
}
