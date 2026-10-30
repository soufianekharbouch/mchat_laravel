<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProvinceController extends Controller
{
    /**
     * Check user permissions.
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
     * List provinces and zones.
     */
    public function index()
    {
        $this->ensurePermission('zones.view');

        $provinces = Province::with([
            'zones' => function ($query) {
                $query->orderBy('name');
            }
        ])
            ->orderBy('name')
            ->get();

        return view(
            'provinces_zones.index',
            compact('provinces')
        );
    }

    /**
     * Show province creation form.
     */
    public function create()
    {
        $this->ensurePermission('zones.create');

        return view('provinces_zones.create');
    }

    /**
     * Store new province.
     */
    public function store(Request $request)
    {
        $this->ensurePermission('zones.create');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('provinces', 'name'),
            ],
            'name_en' => [
                'required',
                'string',
                'max:255',
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('provinces', 'code'),
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
     * Show province edit form.
     */
    public function edit(Province $province)
    {
        $this->ensurePermission('zones.update');

        $province->load([
            'zones' => function ($query) {
                $query->orderBy('name');
            }
        ]);

        return view(
            'provinces_zones.edit',
            compact('province')
        );
    }

    /**
     * Update province.
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
            'name_en' => [
                'required',
                'string',
                'max:255',
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
     * Delete province.
     */
    public function destroy(Province $province)
    {
        $this->ensurePermission('zones.delete');

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
     * Add zone to province.
     */
    public function storeZone(
        Request $request,
        Province $province
    ) {
        $this->ensurePermission('zones.add');

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('zones', 'name')
                    ->where('province_id', $province->id),
            ],
            'name_en' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $province->zones()->create($data);

        return back()->with(
            'success',
            'Zone added successfully.'
        );
    }

    /**
     * Update existing zone.
     */
    public function updateZone(
        Request $request,
        Province $province,
        Zone $zone
    ) {
        $this->ensurePermission('zones.update');

        abort_unless(
            (int) $zone->province_id === (int) $province->id,
            404
        );

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('zones', 'name')
                    ->where('province_id', $province->id)
                    ->ignore($zone->id),
            ],
            'name_en' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $zone->update($data);

        return back()->with(
            'success',
            'Zone updated successfully.'
        );
    }

    /**
     * Delete zone from province.
     */
    public function destroyZone(
        Province $province,
        Zone $zone
    ) {
        $this->ensurePermission('zones.remove');

        abort_unless(
            (int) $zone->province_id === (int) $province->id,
            404
        );

        $zone->delete();

        return back()->with(
            'success',
            'Zone deleted successfully.'
        );
    }
}
