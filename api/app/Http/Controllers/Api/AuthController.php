<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Stage;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Login por sessão (Sanctum SPA cookie). */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha inválidos.',
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()->load('company')]);
    }

    /**
     * Cadastro self-service: cria a EMPRESA (tenant) + o usuário DONO e já autentica.
     * O dono é admin da própria empresa (is_admin), mas NUNCA super-admin da plataforma.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'company' => 'required|string|max:120',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $company = Company::create([
            'name' => $data['company'],
            'slug' => $this->uniqueSlug($data['company']),
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
        ]);
        $user->is_admin = true; // dono da empresa (mas não da plataforma)
        $user->save();

        // Semeia o funil padrão da nova empresa (carimbado com o company_id certo).
        app(Tenancy::class)->run($company->id, function () {
            $this->seedDefaultStages();
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->load('company')], 201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ok']);
    }

    /** Usuário autenticado atual (com a empresa) — consumido pelo front. */
    public function me(Request $request)
    {
        return $request->user()->load('company');
    }

    /** Lista de usuários da PRÓPRIA empresa (somente admin). */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json([
            'users' => User::where('company_id', $request->user()->company_id)
                ->orderBy('name')->get(),
        ]);
    }

    /** Admin cria usuários DENTRO da própria empresa. */
    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'is_admin' => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $request->user()->company_id, // sempre a empresa do criador
        ]);

        if (! empty($data['is_admin'])) {
            $user->is_admin = true;
            $user->save();
        }

        return response()->json(['user' => $user], 201);
    }

    /** Gera um slug único para a empresa (usado na URL do portal público). */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $slug = $base;
        $i = 2;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Funil padrão para uma empresa recém-criada. */
    private function seedDefaultStages(): void
    {
        $defaults = [
            ['key' => 'novo', 'name' => 'Novo lead', 'color' => '#53bdeb', 'position' => 0],
            ['key' => 'contato', 'name' => 'Contato feito', 'color' => '#7c6cf5', 'position' => 1],
            ['key' => 'proposta', 'name' => 'Proposta enviada', 'color' => '#3aa6ff', 'position' => 2],
            ['key' => 'negociacao', 'name' => 'Negociação', 'color' => '#ffb443', 'position' => 3],
            ['key' => 'fechado', 'name' => 'Fechado', 'color' => '#25D366', 'position' => 4],
        ];
        foreach ($defaults as $s) {
            Stage::create($s);
        }
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }
}
