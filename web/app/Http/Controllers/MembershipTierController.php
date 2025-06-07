<?php

namespace App\Http\Controllers;

use App\Lib\OrderDiscounts;
use App\Models\MembershipTier;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MembershipTierController extends Controller
{
    /**
     * Display a listing of the membership tiers.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $shop = $request->attributes->get('shopify_session')->getShop();
            $tiers = MembershipTier::where('shop', $shop)->get();

            return response()->json([
                'success' => true,
                'data' => $tiers,
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to fetch membership tiers: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch membership tiers.',
            ], 500);
        }
    }

    /**
     * Show the form for creating a new membership tier.
     * (Optional: If you have a frontend form, otherwise skip.)
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        // This method is optional and depends on your frontend setup.
        // If you're using a frontend form, you can return a view here.
        return view('membership_tiers.create');
    }

    /**
     * Store a newly created membership tier in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $shop = $request->attributes->get('shopify_session')->getShop();

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'minimum_spend' => 'required|numeric|min:0',
                'discount_value' => 'required|numeric|min:0|max:999.99',
                'discount_type' => 'required|in:percentage,fixed',
                'is_active' => 'boolean',
            ]);

            $tier = new MembershipTier();
            $tier->shop = $shop;
            $tier->name = $validated['name'];
            $tier->description = $validated['description'] ?? null;
            $tier->minimum_spend = $validated['minimum_spend'];
            $tier->discount_value = $validated['discount_value'];
            $tier->discount_type = $validated['discount_type'];
            $tier->is_active = $validated['is_active'] ?? true;
            $tier->save();

            // Trigger order discount creation
            $this->updateOrderDiscounts($shop);

            return response()->json([
                'success' => true,
                'message' => 'Membership tier created successfully.',
                'data' => $tier,
            ], 201);
        } catch (\Exception $e) {
            Log::error("Failed to create membership tier: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Failed to create membership tier.',
            ], 500);
        }
    }

    /**
     * Display the specified membership tier.
     *
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        try {
            $shop = $request->attributes->get('shopify_session')->getShop();
            $tier = MembershipTier::where('shop', $shop)->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $tier,
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to fetch membership tier: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Membership tier not found.',
            ], 404);
        }
    }

    /**
     * Show the form for editing the specified membership tier.
     * (Optional: If you have a frontend form, otherwise skip.)
     *
     * @param int $id
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function edit($id, Request $request)
    {
        $shop = $request->attributes->get('shopify_session')->getShop();
        $tier = MembershipTier::where('shop', $shop)->findOrFail($id);

        // This method is optional and depends on your frontend setup.
        return view('membership_tiers.edit', compact('tier'));
    }

    /**
     * Update the specified membership tier in storage.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $shop = $request->attributes->get('shopify_session')->getShop();
            $tier = MembershipTier::where('shop', $shop)->findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'minimum_spend' => 'required|numeric|min:0',
                'discount_value' => 'required|numeric|min:0|max:999.99',
                'discount_type' => 'required|in:percentage,fixed',
                'is_active' => 'boolean',
            ]);

            $tier->name = $validated['name'];
            $tier->description = $validated['description'] ?? null;
            $tier->minimum_spend = $validated['minimum_spend'];
            $tier->discount_value = $validated['discount_value'];
            $tier->discount_type = $validated['discount_type'];
            $tier->is_active = $validated['is_active'] ?? $tier->is_active;
            $tier->save();

            // Trigger order discount creation
            $this->updateOrderDiscounts($shop);

            return response()->json([
                'success' => true,
                'message' => 'Membership tier updated successfully.',
                'data' => $tier,
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to update membership tier: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Failed to update membership tier.',
            ], 500);
        }
    }

    /**
     * Remove the specified membership tier from storage.
     *
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id, Request $request)
    {
        try {
            $shop = $request->attributes->get('shopify_session')->getShop();
            $tier = MembershipTier::where('shop', $shop)->findOrFail($id);

            $tier->delete();

            // Trigger order discount creation
            $this->updateOrderDiscounts($shop);

            return response()->json([
                'success' => true,
                'message' => 'Membership tier deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            Log::error("Failed to delete membership tier: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete membership tier.',
            ], 500);
        }
    }

    /**
     * Helper method to update Shopify order discounts after a tier change.
     *
     * @param string $shop
     * @return void
     */
    protected function updateOrderDiscounts($shop)
    {
        try {
            $session = Session::where('shop', $shop)->firstOrFail();
            $orderDiscounts = new OrderDiscounts($session);
            $result = $orderDiscounts->run();

            if (!$result) {
                Log::error("Failed to update order discounts: {$orderDiscounts->last_message}");
            } else {
                Log::info("Order discounts updated successfully after membership tier change.");
            }
        } catch (\Exception $e) {
            Log::error("Exception while updating order discounts: {$e->getMessage()}");
        }
    }
}
