<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'confirmation_alert_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'enforce_status_transitions' => ['sometimes', 'boolean'],
            'status_on_confirm_id' => ['sometimes', 'nullable', 'integer', 'exists:delivery_statuses,id'],
            'status_on_assign_id' => ['sometimes', 'nullable', 'integer', 'exists:delivery_statuses,id'],
            'driver_action_status_ids' => ['sometimes', 'array'],
            'driver_action_status_ids.*' => ['nullable', 'integer', 'exists:delivery_statuses,id'],
            'default_tariffs' => ['sometimes', 'array'],
            'default_tariffs.livraison' => ['required_with:default_tariffs', 'numeric', 'min:0'],
            'default_tariffs.ramassage' => ['required_with:default_tariffs', 'numeric', 'min:0'],
            'default_tariffs.depot_partenaire' => ['required_with:default_tariffs', 'numeric', 'min:0'],
            'default_tariffs.retour' => ['required_with:default_tariffs', 'numeric', 'min:0'],
            'default_tariffs.echange' => ['required_with:default_tariffs', 'numeric', 'min:0'],
        ]);
        if (isset($data['driver_action_status_ids'])) {
            $data['driver_action_status_ids'] = array_intersect_key($data['driver_action_status_ids'], \App\Support\Catalog::DRIVER_ACTIONS);
        }
        if (isset($data['default_tariffs'])) {
            $data['default_tariffs'] = array_map('floatval', array_intersect_key(
                $data['default_tariffs'], Setting::DEFAULTS['default_tariffs']
            ));
        }
        foreach ($data as $key => $value) {
            Setting::setValue($key, $value);
        }

        return response()->json(['data' => $this->payload()]);
    }

    protected function payload(): array
    {
        return [
            'company_name' => Setting::getValue('company_name'),
            'confirmation_alert_hours' => (int) Setting::getValue('confirmation_alert_hours'),
            'default_tariffs' => Setting::defaultTariffs(),
            'enforce_status_transitions' => (bool) Setting::getValue('enforce_status_transitions', false),
            'status_on_confirm_id' => Setting::getValue('status_on_confirm_id'),
            'status_on_assign_id' => Setting::getValue('status_on_assign_id'),
            'driver_action_status_ids' => (object) (array) Setting::getValue('driver_action_status_ids', []),
        ];
    }
}
