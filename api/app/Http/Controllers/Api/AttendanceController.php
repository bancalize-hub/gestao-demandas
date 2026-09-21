<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Attendance;
use Illuminate\Http\Request;

/**
 * Horário de atendimento da empresa (tela Admin → Horário de atendimento).
 *
 * O payload devolvido é sempre o SANEADO (Attendance::da), nunca o cru do banco: a tela
 * mostra exatamente a régua que os ticks vão obedecer, inclusive quando o banco está
 * NULL (aí aparece o padrão seg–sex 09:00–19:00).
 */
class AttendanceController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user()->company;

        return response()->json(Attendance::da($company) + [
            'configured' => is_array($company?->attendance),
        ]);
    }

    public function update(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
        ]);

        $company = $request->user()->company;
        $company->attendance = [
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'start' => $data['start'],
            'end' => $data['end'],
        ];
        $company->save();

        return response()->json(Attendance::da($company) + ['configured' => true]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }
}
