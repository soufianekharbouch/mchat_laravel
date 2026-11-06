<?php

namespace App\Http\Controllers;

use App\Models\MobileNotification;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\ReminderPlanner;
use App\Support\AppNotifications\NotificationDestinations;
use App\Support\AppNotifications\NotificationTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notifications > Automatic types: on/off, texts, timing and destination
 * of each automatic notification (list in NotificationTypes).
 */
class NotificationTypeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission(NotificationController::PERMISSION_VIEW);

        if (!NotificationTypes::installed()) {
            return redirect()->route('notifications.index');
        }

        $types = collect(NotificationTypes::all())->map(fn ($definition, $key) => [
            'key' => $key,
            'definition' => $definition,
            'settings' => NotificationTypes::settings($key),
            'destinations' => NotificationDestinations::forType($key),
            'scheduled' => MobileNotification::where('auto_type', $key)->where('status', 'scheduled')->count(),
            'sent_30' => MobileNotification::where('auto_type', $key)->where('status', 'sent')->where('sent_at', '>=', now()->subDays(30))->count(),
        ]);

        return view('notifications.types', [
            'types' => $types,
            'vars' => NotificationTypes::VARS,
            'canManage' => $request->user()->hasPermission(NotificationController::PERMISSION_SETTINGS),
        ]);
    }

    public function update(Request $request, string $key, NotificationCenter $center, ReminderPlanner $planner): RedirectResponse
    {
        $this->authorizePermission(NotificationController::PERMISSION_SETTINGS);

        $definition = NotificationTypes::get($key);
        abort_unless($definition, 404);

        $reminder = $definition['kind'] === 'reminder';

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'body_en' => ['required', 'string', 'max:2000'],
            'body_ar' => ['required', 'string', 'max:2000'],
            'destination' => ['required', Rule::in(array_keys(NotificationDestinations::forType($key)))],
            'delay_minutes' => [$reminder ? 'nullable' : 'required', 'integer', 'min:0', 'max:1440'],
            'days_before' => [$reminder ? 'required' : 'nullable', 'string', 'max:30', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'send_time' => [$reminder ? 'required' : 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'apply_scheduled' => ['nullable', 'boolean'],
        ], [
            'days_before.regex' => 'Days before: numbers separated by commas, e.g. 5,1',
            'send_time.regex' => 'Time: HH:MM, e.g. 10:00',
        ]);

        $settings = NotificationTypes::settings($key);

        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'title_en' => trim($data['title_en']),
            'title_ar' => trim($data['title_ar']),
            'body_en' => trim($data['body_en']),
            'body_ar' => trim($data['body_ar']),
            'destination' => $data['destination'],
            'delay_minutes' => $reminder ? 0 : (int) $data['delay_minutes'],
            'days_before' => $reminder ? preg_replace('/\s+/', '', $data['days_before']) : null,
            'send_time' => $reminder ? $data['send_time'] : null,
            'updated_by' => $request->user()->id,
        ])->save();

        NotificationTypes::forgetCache();

        $message = NotificationTypes::label($key) . ' saved.';

        // Scheduled notifications of this type not changed by hand: new texts / destination
        if ($request->boolean('apply_scheduled')) {
            $updated = 0;

            MobileNotification::where('auto_type', $key)
                ->where('status', 'scheduled')
                ->whereNull('edited_at')
                ->get()
                ->each(function (MobileNotification $notification) use ($center, $data, &$updated) {
                    $notification->forceFill(['destination' => $data['destination']])->save();
                    $center->rerender($notification, $notification->context['vars'] ?? []);
                    $updated++;
                });

            $message .= " {$updated} scheduled notification(s) updated.";
        }

        // Reminders: new days / time applied now
        if ($reminder) {
            $result = $planner->planAll();
            $message .= " Reminders: {$result['created']} created, {$result['updated']} moved, {$result['cancelled']} cancelled.";
        }

        return redirect()->route('notification-types.index', ['open' => $key])->with('success', $message);
    }

    /** Button "Plan the reminders now" (instead of waiting for the hourly task). */
    public function planReminders(ReminderPlanner $planner): RedirectResponse
    {
        $this->authorizePermission(NotificationController::PERMISSION_SETTINGS);

        $result = $planner->planAll();

        return back()->with('success', "Reminders: {$result['created']} created, {$result['updated']} moved, {$result['cancelled']} cancelled.");
    }

    private function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasPermission($permission), 403);
    }
}
