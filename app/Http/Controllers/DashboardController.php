<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->hasPermission('users.view')) {
            return redirect()->route('users.index');
        }

        if ($user->hasPermission('ingredients.view')) {
            return redirect()->route('ingredients.index');
        }

        if ($user->hasPermission('daily_reports.view')) {
            return redirect()->route('daily-report.index');
        }

        if ($user->hasPermission('recipes.view')) {
            return redirect()->route('recipes.index');
        }

        if ($user->hasPermission('subscriptions.view')) {
            return redirect()->route('subscriptions.index');
        }

        if ($user->hasPermission('zones.view')) {
            return redirect()->route('provinces.index');
        }

        if ($user->hasPermission('settings.view')) {
            return redirect()->route('settings.index');
        }

        Auth::logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('error', 'Your account does not have any assigned permissions.');
    }
}
