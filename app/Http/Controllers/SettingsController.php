<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::instance();
        return view('settings.index', compact('settings'));
    }

    public function edit()
    {
        $settings = Setting::instance();
        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $settings = Setting::instance();

        $data = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],
            'logo_premium' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:4096'],

            'pdf_first_delivery' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
            'pdf_last_delivery' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],

            'meal_storage_instructions' => ['nullable', 'string'],
            'welcome_message' => ['nullable', 'string'],
            'subscription_qr_message' => ['nullable', 'string'],
            'last_order_subscription_message' => ['nullable', 'string'],
            'pdf_lang' => ['required', 'in:ar,en'],
        ]);

        if ($request->hasFile('logo')) {
            if ($settings->logo) {
                Storage::disk('public')->delete($settings->logo);
            }
            $settings->logo = $request->file('logo')->store('settings', 'public');
        }

        if ($request->hasFile('logo_premium')) {
            if ($settings->logo_premium) {
                Storage::disk('public')->delete($settings->logo_premium);
            }
            $settings->logo_premium = $request->file('logo_premium')->store('settings', 'public');
        }

        if ($request->hasFile('pdf_first_delivery')) {
            if ($settings->pdf_first_delivery) {
                Storage::disk('public')->delete($settings->pdf_first_delivery);
            }
            $settings->pdf_first_delivery = $request->file('pdf_first_delivery')->store('settings', 'public');
        }

        if ($request->hasFile('pdf_last_delivery')) {
            if ($settings->pdf_last_delivery) {
                Storage::disk('public')->delete($settings->pdf_last_delivery);
            }
            $settings->pdf_last_delivery = $request->file('pdf_last_delivery')->store('settings', 'public');
        }

        $settings->meal_storage_instructions = $data['meal_storage_instructions'] ?? null;
        $settings->welcome_message = $data['welcome_message'] ?? null;
        $settings->subscription_qr_message = $data['subscription_qr_message'] ?? null;
        $settings->last_order_subscription_message = $data['last_order_subscription_message'] ?? null;
        $settings->pdf_lang = $data['pdf_lang'];

        $settings->save();

        return redirect()->route('settings.index')->with('success', 'Settings updated.');
    }
}