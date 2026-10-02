<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LogisticsPartnerResource;
use App\Models\LogisticsPartner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paramètres → Partenaires logistiques. Every query is scoped to the signed-in user's company;
 * a partner of another company answers 404.
 */
class LogisticsPartnerController extends Controller
{
    protected function companyId(Request $request): int
    {
        return $request->user()->resolveCompanyId();
    }

    protected function authorizePartner(Request $request, LogisticsPartner $partner): void
    {
        abort_unless((int) $partner->company_id === $this->companyId($request), 404);
    }

    /**
     * ?category=ramassage|depot (includes "both"), ?active=1 (selectors) or ?status=active|inactive, ?q=search.
     * Always favourites first, then by name.
     */
    public function index(Request $request)
    {
        $q = LogisticsPartner::query()->forCompany($this->companyId($request))->withCount('missions');

        if (in_array($request->query('category'), ['ramassage', 'depot'], true)) {
            $q->forCategory($request->query('category'));
        } elseif (in_array($request->query('type'), array_keys(LogisticsPartner::TYPES), true)) {
            $q->where('type', $request->query('type'));
        }
        if ($request->boolean('active') || $request->query('status') === 'active') {
            $q->active();
        } elseif ($request->query('status') === 'inactive') {
            $q->where('is_active', false);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%"));
        }

        return LogisticsPartnerResource::collection($q->ordered()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $partner = LogisticsPartner::create($data + ['company_id' => $this->companyId($request)]);

        return (new LogisticsPartnerResource($partner->loadCount('missions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, LogisticsPartner $logisticsPartner)
    {
        $this->authorizePartner($request, $logisticsPartner);
        $data = $request->validate($this->rules($request, $logisticsPartner));
        $logisticsPartner->update($data);

        return new LogisticsPartnerResource($logisticsPartner->loadCount('missions'));
    }

    public function deactivate(Request $request, LogisticsPartner $logisticsPartner)
    {
        $this->authorizePartner($request, $logisticsPartner);
        $logisticsPartner->update(['is_active' => false]);

        return new LogisticsPartnerResource($logisticsPartner->loadCount('missions'));
    }

    public function activate(Request $request, LogisticsPartner $logisticsPartner)
    {
        $this->authorizePartner($request, $logisticsPartner);
        $logisticsPartner->update(['is_active' => true]);

        return new LogisticsPartnerResource($logisticsPartner->loadCount('missions'));
    }

    public function favorite(Request $request, LogisticsPartner $logisticsPartner)
    {
        $this->authorizePartner($request, $logisticsPartner);
        $value = $request->has('is_favorite') ? $request->boolean('is_favorite') : ! $logisticsPartner->is_favorite;
        $logisticsPartner->update(['is_favorite' => $value]);

        return new LogisticsPartnerResource($logisticsPartner->loadCount('missions'));
    }

    /** Hard delete only for partners never used by a mission; used partners must be deactivated. */
    public function destroy(Request $request, LogisticsPartner $logisticsPartner)
    {
        $this->authorizePartner($request, $logisticsPartner);
        if ($logisticsPartner->missions()->exists()) {
            $msg = 'Ce partenaire est déjà utilisé dans l’historique des missions : désactivez-le au lieu de le supprimer.';

            return response()->json(['message' => $msg, 'errors' => ['partner' => [$msg]]], 422);
        }
        $logisticsPartner->delete();

        return response()->noContent();
    }

    protected function rules(Request $request, ?LogisticsPartner $partner = null): array
    {
        $req = $partner ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255', Rule::unique('logistics_partners', 'name')
                ->where('company_id', $this->companyId($request))
                ->ignore($partner?->id)],
            'type' => [$req, Rule::in(array_keys(LogisticsPartner::TYPES))],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'is_favorite' => ['sometimes', 'boolean'],
        ];
    }
}
