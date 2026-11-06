<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\Notifications\NotificationCenter;
use App\Support\PremiumPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PREMIUM-... pack: after contacting the customer, the team assigns the
 * product created for him (his own recipes). The purchase becomes
 * "treated" and the customer can plan his deliveries in the app.
 *
 *   POST /purchases/{purchase}/premium-product
 *
 * Kept from the original purchase: price paid, payment, dates, pet.
 * Taken from the new product: recipes, number of recipes, delivery rules.
 */
class PurchasePremiumController extends Controller
{
    public function assignProduct(Request $request, Purchase $purchase): RedirectResponse
    {
        $this->authorizePermission('purchases.update');

        if (!PremiumPack::isPending($purchase)) {
            return back()->withErrors(['premium' => 'This purchase is not an untreated premium pack.']);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $product = Product::with('recipes')->findOrFail($data['product_id']);

        if ($product->species !== $purchase->species) {
            return back()->withErrors(['product_id' => "The product is for {$product->species}, the pet of this purchase is a {$purchase->species}."]);
        }

        if (PremiumPack::isPremiumProduct($product)) {
            return back()->withErrors(['product_id' => 'Choose the custom product created for this customer, not a PREMIUM- product.']);
        }

        if ($product->recipes->isEmpty()) {
            return back()->withErrors(['product_id' => 'This product has no recipe: add his recipes to the product first.']);
        }

        $original = [
            'id' => $purchase->product_id,
            'code' => $purchase->product_code,
            'name' => $purchase->product_name,
        ];

        DB::transaction(function () use ($purchase, $product, $original) {
            // The customer keeps the price he paid
            $paid = $purchase->only(['unit_price', 'discount_amount', 'total_amount', 'price_source', 'currency', 'starts_on', 'ends_on']);

            $purchase->fillFromProduct($product);
            $purchase->fill($paid);

            $purchase->premium_status = PremiumPack::DONE;
            $purchase->premium_original_product_id = $original['id'];
            $purchase->premium_original_product_code = $original['code'];
            $purchase->premium_processed_at = now();
            $purchase->premium_processed_by = auth()->id();

            $purchase->admin_note = trim(($purchase->admin_note ? $purchase->admin_note . "\n" : '')
                . '[' . now()->format('d/m/Y H:i') . '] Premium treated by ' . (auth()->user()?->name ?? 'admin')
                . ": {$original['code']} -> {$product->code}");

            $purchase->save();
        });

        if ($request->boolean('notify', true)) {
            $this->notifyCustomer($purchase);
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', "Custom product {$product->code} assigned. The customer can now plan his deliveries.");
    }

    /**
     * Notification "Your custom recipes are ready" (Notifications menu:
     * automatic type premium_ready, texts and destination set there).
     */
    private function notifyCustomer(Purchase $purchase): void
    {
        app(NotificationCenter::class)->premiumReady($purchase->fresh() ?? $purchase);
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
