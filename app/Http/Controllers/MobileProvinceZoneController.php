<?php

namespace App\Http\Controllers;

use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileProvinceZoneController extends Controller
{
    /**
     * Liste publique des provinces et de leurs zones.
     *
     * GET /api/mobile/provinces-zones?lang=ar
     *
     * name    : libellé anglais
     * name_ar : libellé arabe
     * label   : libellé dans la langue demandée (repli sur l'anglais si l'arabe est vide)
     */
    public function index(Request $request): JsonResponse
    {
        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';

        $label = function ($item) use ($lang) {
            return $lang === 'ar' && filled($item->name_ar)
                ? $item->name_ar
                : $item->name;
        };

        $provinces = Province::with([
            'zones' => fn ($query) => $query->orderBy('name'),
        ])
            ->orderBy('name')
            ->get()
            ->map(fn ($province) => [
                'id' => $province->id,
                'code' => $province->code,
                'name' => $province->name,
                'name_ar' => $province->name_ar,
                'label' => $label($province),
                'zones' => $province->zones->map(fn ($zone) => [
                    'id' => $zone->id,
                    'province_id' => $zone->province_id,
                    'name' => $zone->name,
                    'name_ar' => $zone->name_ar,
                    'label' => $label($zone),
                ])->values(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'lang' => $lang,
            'provinces' => $provinces,
        ]);
    }
}
