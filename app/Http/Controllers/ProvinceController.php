<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvinceController extends Controller
{
    /**
     * Vérifie que l'utilisateur connecté possède la permission demandée.
     */
    private function ensurePermission(string $permission): void
    {
        abort_unless(
            auth()->check()
            && auth()->user()->hasPermission($permission),
            403,
            'You do not have permission to perform this action.'
        );
    }

    /**
     * Afficher la liste des provinces et des zones.
     */
    public function index()
    {
        $this->ensurePermission('zones.view');

        $provinces = Province::with('zones')
            ->orderBy('name')
            ->get();

        return view(
            'provinces_zones.index',
            compact('provinces')
        );
    }

    /**
     * Afficher le formulaire de création d'une province.
     */
    public function create()
    {
        $this->ensurePermission('zones.create');

        return view('provinces_zones.create');
    }

    /**
     * Enregistrer une nouvelle province.
     */
    public function store(Request $request)
    {
        $this->ensurePermission('zones.create');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:provinces,name',
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                'unique:provinces,code',
            ],
        ]);

        Province::create($data);

        return redirect()
            ->route('provinces-zones.index')
            ->with(
                'success',
                'Province created successfully.'
            );
    }

    /**
     * Afficher le formulaire de modification d'une province.
     */
    public function edit(Province $province)
    {
        $this->ensurePermission('zones.update');

        $province->load('zones');

        return view(
            'provinces_zones.edit',
            compact('province')
        );
    }

    /**
     * Modifier une province.
     */
    public function update(
        Request $request,
        Province $province
    ) {
        $this->ensurePermission('zones.update');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('provinces', 'name')
                    ->ignore($province->id),
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('provinces', 'code')
                    ->ignore($province->id),
            ],
        ]);

        $province->update($data);

        return redirect()
            ->route('provinces-zones.index')
            ->with(
                'success',
                'Province updated successfully.'
            );
    }

    /**
     * Supprimer une province.
     */
    public function destroy(Province $province)
    {
        $this->ensurePermission('zones.delete');

        /*
         * Empêche la suppression si la province contient encore des zones.
         */
        if ($province->zones()->exists()) {
            return redirect()
                ->route('provinces-zones.index')
                ->with(
                    'error',
                    'This province cannot be deleted because it contains zones.'
                );
        }

        $province->delete();

        return redirect()
            ->route('provinces-zones.index')
            ->with(
                'success',
                'Province deleted successfully.'
            );
    }

    /**
     * Ajouter une zone à une province.
     */
    public function storeZone(
        Request $request,
        Province $province
    ) {
        $this->ensurePermission('zones.create');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',

                /*
                 * Empêche les doublons de zones dans la même province.
                 */
                Rule::unique('zones', 'name')
                    ->where(function ($query) use ($province) {
                        return $query->where(
                            'province_id',
                            $province->id
                        );
                    }),
            ],
        ]);

        $province->zones()->create($data);

        return back()->with(
            'success',
            'Zone added successfully.'
        );
    }

    /**
     * Supprimer une zone d'une province.
     */
    public function destroyZone(
        Province $province,
        Zone $zone
    ) {
        $this->ensurePermission('zones.delete');

        /*
         * Vérifie que la zone appartient réellement à la province.
         */
        if ((int) $zone->province_id !== (int) $province->id) {
            abort(404);
        }

        $zone->delete();

        return back()->with(
            'success',
            'Zone deleted successfully.'
        );
    }
}