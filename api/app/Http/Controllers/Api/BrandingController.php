<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Branding da empresa (tenant): cor de destaque + logos clara/escura.
 * Leitura é livre para membros; alteração é só do admin da empresa.
 */
class BrandingController extends Controller
{
    /** Branding da empresa atual (para o front renderizar cor/logos). */
    public function show(Request $request)
    {
        return response()->json($this->payload($request->user()->company));
    }

    /** Atualiza a cor de destaque (admin). */
    public function update(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            // Hex de 3 ou 6 dígitos; null limpa (volta ao padrão).
            'brand_color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ]);

        $company = $request->user()->company;
        $company->brand_color = $data['brand_color'] ?? null;
        $company->save();

        return response()->json($this->payload($company));
    }

    /** Envia/atualiza uma logo (variant=light|dark). Admin. */
    public function uploadLogo(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'variant' => 'required|in:light,dark',
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp,svg,gif|max:2048',
        ]);

        $company = $request->user()->company;
        $column = $data['variant'] === 'light' ? 'logo_light' : 'logo_dark';

        // Remove a anterior, se houver.
        if ($company->$column) {
            Storage::disk('public')->delete($company->$column);
        }

        $ext = $request->file('logo')->getClientOriginalExtension() ?: 'png';
        $path = $request->file('logo')->storeAs(
            'branding',
            'co'.$company->id.'-'.$data['variant'].'-'.Str::lower(Str::random(6)).'.'.$ext,
            'public'
        );
        $company->$column = $path;
        $company->save();

        return response()->json($this->payload($company));
    }

    /** Remove uma logo (variant=light|dark). Admin. */
    public function removeLogo(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate(['variant' => 'required|in:light,dark']);
        $company = $request->user()->company;
        $column = $data['variant'] === 'light' ? 'logo_light' : 'logo_dark';

        if ($company->$column) {
            Storage::disk('public')->delete($company->$column);
            $company->$column = null;
            $company->save();
        }

        return response()->json($this->payload($company));
    }

    /**
     * Branding PÚBLICO por slug (sem login) — usado pelo portal do cliente
     * (/solicitar?e=slug). Só expõe cor e logos, nada sensível.
     */
    public function publicBranding(string $slug)
    {
        $company = Company::where('slug', $slug)->where('is_active', true)->first();
        abort_unless($company, 404, 'Empresa não encontrada.');

        return response()->json([
            'name' => $company->name,
            'brand_color' => $company->brand_color,
            'logo_light_url' => $company->logo_light_url,
            'logo_dark_url' => $company->logo_dark_url,
        ]);
    }

    /**
     * Marca da empresa PRINCIPAL (dona da instância) — usada nas páginas
     * públicas de login/cadastro, que não têm empresa no contexto. Convenção:
     * a primeira empresa ativa (menor id) é a dona da instância.
     */
    public function primaryBranding()
    {
        $company = Company::where('is_active', true)->orderBy('id')->first();

        return response()->json([
            'name' => $company?->name,
            'brand_color' => $company?->brand_color,
            'logo_light_url' => $company?->logo_light_url,
            'logo_dark_url' => $company?->logo_dark_url,
        ]);
    }

    private function payload(Company $company): array
    {
        return [
            'brand_color' => $company->brand_color,
            'logo_light_url' => $company->logo_light_url,
            'logo_dark_url' => $company->logo_dark_url,
        ];
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }
}
