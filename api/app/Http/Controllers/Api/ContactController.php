<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    private function googleUser(Request $request): ?User
    {
        $u = $request->user();
        if ($u && $u->hasGoogle()) {
            return $u;
        }

        return User::whereNotNull('google_refresh_token')->first();
    }

    /** `?list=<id>` filtra por lista; sem ele, a agenda inteira. */
    public function index(Request $request)
    {
        $lista = (int) $request->query('list', 0);

        return Contact::with('lists:id,name')
            ->when($lista > 0, fn ($q) => $q->whereHas('lists', fn ($l) => $l->where('contact_lists.id', $lista)))
            ->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
        ]);

        $contact = Contact::create($data);

        // Escreve no Google na hora.
        if ($gu = $this->googleUser($request)) {
            try {
                $r = $this->google->createGoogleContact($gu, $data['name'], $data['phone'] ?? null, $data['email'] ?? null);
                $contact->update(['resource_name' => $r['resource_name'], 'etag' => $r['etag'], 'google_user_id' => $gu->id]);
            } catch (\Throwable $e) {
                Log::warning('Google createContact falhou', ['e' => $e->getMessage()]);
            }
        }

        return response()->json($contact, 201);
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
        ]);
        $contact->update($data);

        if (($gu = $this->googleUser($request)) && $contact->resource_name) {
            try {
                $etag = $this->google->updateGoogleContact(
                    $gu, $contact->resource_name, $contact->etag,
                    $contact->name, $contact->phone, $contact->email
                );
                $contact->update(['etag' => $etag]);
            } catch (\Throwable $e) {
                Log::warning('Google updateContact falhou', ['e' => $e->getMessage()]);
            }
        } elseif ($gu = $this->googleUser($request)) {
            // Não tinha no Google ainda → cria.
            try {
                $r = $this->google->createGoogleContact($gu, $contact->name, $contact->phone, $contact->email);
                $contact->update(['resource_name' => $r['resource_name'], 'etag' => $r['etag'], 'google_user_id' => $gu->id]);
            } catch (\Throwable $e) {
                Log::warning('Google createContact (via update) falhou', ['e' => $e->getMessage()]);
            }
        }

        return $contact;
    }

    public function destroy(Request $request, Contact $contact)
    {
        if (($gu = $this->googleUser($request)) && $contact->resource_name) {
            try {
                $this->google->deleteGoogleContact($gu, $contact->resource_name);
            } catch (\Throwable $e) {
                Log::warning('Google deleteContact falhou', ['e' => $e->getMessage()]);
            }
        }
        $contact->delete();

        return response()->json(['message' => 'ok']);
    }
}
