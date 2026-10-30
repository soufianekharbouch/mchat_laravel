<?php

namespace App\Http\Controllers;

use App\Models\MobileNotification;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Support\PremiumPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * PREMIUM-... packs: the team chooses the recipes of ONE purchase
 * (after contacting the customer). Then the customer plans his deliveries
 * in the app with these recipes only.
 *
 *   GET  /purchases-premium                       purchases waiting for their recipes
 *   POST /purchases/{purchase}/custom-recipes     save the recipes (+ push to the customer)
 */
class PurchaseCustomRecipesController extends Controller
{
    public function index(): View
    {
        $this->authorizePermission('purchases.view');

        $awaiting = PremiumPack::awaitingQuery()
            ->with(['animal', 'subscription'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $recent = Purchase::query()
            ->where('product_code', 'like', PremiumPack::CODE_PREFIX . '%')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('purchase_recipes')
                ->whereColumn('purchase_recipes.purchase_id', 'purchases.id'))
            ->with(['animal', 'subscription'])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return view('purchases.premium', compact('awaiting', 'recent'));
    }

    public function save(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorizePermission('purchases.update');

        abort_unless(PremiumPack::isPremiumPurchase($purchase), 404);

        $data = $request->validate([
            'recipes' => ['required', 'array'],
            'recipes.*.selected' => ['nullable', 'boolean'],
            'recipes.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'default_recipe_id' => ['nullable', 'integer'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $selected = collect($data['recipes'])
            ->filter(fn ($row) => !empty($row['selected']))
            ->map(fn ($row) => isset($row['max_quantity']) && $row['max_quantity'] !== '' ? (int) $row['max_quantity'] : null);

        if ($selected->isEmpty()) {
            return back()->withErrors(['recipes' => 'Choose at least one recipe for this customer.'])->withInput();
        }

        $recipes = Recipe::whereIn('id', $selected->keys())->get()->keyBy('id');

        // Enough recipes for the pack (when every recipe has a maximum)
        if ($selected->every(fn ($max) => $max !== null) && $selected->sum() < $purchase->total_recipes) {
            return back()->withErrors([
                'recipes' => "The maximums ({$selected->sum()}) are lower than the {$purchase->total_recipes} recipes of the pack.",
            ])->withInput();
        }

        $hadRecipes = PremiumPack::hasRecipes($purchase);

        DB::transaction(function () use ($purchase, $selected, $recipes, $data) {
            DB::table('purchase_recipes')->where('purchase_id', $purchase->id)->delete();

            $order = 0;

            foreach ($selected as $recipeId => $max) {
                if (!$recipes->has($recipeId)) {
                    continue;
                }

                DB::table('purchase_recipes')->insert([
                    'purchase_id' => $purchase->id,
                    'recipe_id' => $recipeId,
                    'max_quantity' => $max,
                    'is_default' => (int) ($data['default_recipe_id'] ?? 0) === (int) $recipeId,
                    'sort_order' => $order++,
                    'assigned_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $names = $recipes->only($selected->keys()->all())->pluck('name')->implode(', ');

            $purchase->forceFill([
                'admin_note' => trim(($purchase->admin_note ? $purchase->admin_note . "\n" : '')
                    . now()->format('d/m/Y H:i') . ' - personalized recipes set by ' . (auth()->user()->name ?? 'staff') . ': ' . $names),
            ])->save();
        });

        // Customer told in the app that he can now plan his deliveries
        if (!$hadRecipes && $request->boolean('notify', true) && $purchase->customer_account_id) {
            $this->notifyCustomer($purchase);
        }

        return back()->with('success', 'Personalized recipes saved. The customer can now plan his deliveries in the app.');
    }

    private function notifyCustomer(Purchase $purchase): void
    {
        try {
            $pet = $purchase->animal?->name ?? $purchase->animal_name;

            $notification = MobileNotification::create([
                'type' => MobileNotification::TYPE_DELIVERY,
                'title_en' => 'Your personalized recipes are ready 🐾',
                'title_ar' => 'وصفاتك المخصصة جاهزة 🐾',
                'body_en' => 'Our team has chosen ' . ($pet ? "{$pet}'s" : 'your') . ' recipes. You can now plan your deliveries.',
                'body_ar' => 'اختار فريقنا وصفات ' . ($pet ?: 'حيوانك') . '. يمكنك الآن تنظيم مواعيد التوصيل.',
                'target_type' => 'selected_customers',
                'status' => 'draft',
            ]);

            DB::table('mobile_notification_recipients')->insert([
                'notification_id' => $notification->id,
                'customer_account_id' => $purchase->customer_account_id,
                'mobile_device_id' => null,
                'push_status' => 'pending',
                'push_sent_at' => null,
                'viewed_at' => null,
                'dismissed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(MobileNotificationController::class)->send(
                Request::create('/internal/premium-recipes', 'POST'),
                $notification->id
            );
        } catch (Throwable $exception) {
            Log::warning('Premium recipes notification failed', ['purchase_id' => $purchase->id, 'error' => $exception->getMessage()]);
        }
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
