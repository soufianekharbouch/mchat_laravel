<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class PdfNewDeliveryController extends Controller
{
    public function newDeliveryPdf(Subscription $subscription, Request $request)
    {
        $date = $request->input('date');
        $carbonDate = Carbon::parse($date)->startOfDay();

        $subscription->load('animals.meals.recipe');

        $firstDeliveryDate = $this->computeFirstDeliveryDate($subscription);

        $settings = Setting::instance();

        $html = view('daily-report.pdf-new-delivery', [
            'subscription'      => $subscription,
            'date'              => $date,
            'settings'          => $settings,
            'firstDeliveryDate' => $firstDeliveryDate,
            'isFirstDelivery'   => $firstDeliveryDate ? $firstDeliveryDate->isSameDay($carbonDate) : false,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="welcome-' . $subscription->code . '-' . $date . '.pdf"',
            ]
        );
    }

    public function subscriptionQrPromoPdf(Subscription $subscription, Request $request)
    {
        $date = (string) $request->input('date');
        $settings = Setting::instance();

        $subscription->load('animals');

        $transitionAnimal = $subscription->animals->first(function ($animal) {
            return !empty($animal->transition) && !empty($animal->qr_code_image);
        });

        $customerName = trim(($subscription->subscriber_first_name ?? '') . ' ' . ($subscription->subscriber_last_name ?? ''));
        if ($customerName === '') {
            $customerName = $subscription->subscriber_first_name ?? 'Customer';
        }

        $template = $settings->subscription_qr_message ?? '';

        if (trim(strip_tags($template)) === '') {
            $template = '
                <p>عزيزنا [customer_name]،</p>
                <p>يسعدنا دعوتكم لإتمام الاشتراك والاستفادة من العرض الخاص الذي تم إعداده خصيصًا لكم.</p>
                <p>[qr_code]</p>
                <p>يرجى إتمام الاشتراك قبل انتهاء هذه الفرصة خلال أسبوع واحد فقط.</p>
            ';
        }

        $qrHtml = '';
        if ($transitionAnimal && !empty($transitionAnimal->qr_code_image)) {
            $qrDiskPath = storage_path('app/public/' . $transitionAnimal->qr_code_image);
            if (file_exists($qrDiskPath)) {
                $qrHtml = '<div style="text-align:center; margin:18px 0;"><img src="' . $qrDiskPath . '" style="width:180px; height:auto;"></div>';
            }
        }

        $renderedTemplate = str_replace(
            ['[customer_name]', '[qr_code]'],
            [e($customerName), $qrHtml],
            $template
        );

        $html = view('daily-report.pdf-subscription-qr-promo', [
            'subscription' => $subscription,
            'date' => $date,
            'settings' => $settings,
            'customerName' => $customerName,
            'renderedTemplate' => $renderedTemplate,
            'transitionAnimal' => $transitionAnimal,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="subscription-qr-promo-' . $subscription->code . '-' . $date . '.pdf"',
            ]
        );
    }

    public function lastOrderPdf(Subscription $subscription, Request $request)
    {
        $date = (string) $request->input('date', now()->toDateString());
        $settings = Setting::instance();

        $subscription->load([
            'animals',
            'plannedOrders.animal',
            'plannedOrders.items.recipe',
        ]);

        $plannedOrders = $subscription->plannedOrders->filter(function ($order) use ($date) {
            return Carbon::parse($order->scheduled_for)->format('Y-m-d') === $date;
        });

        $qrAnimals = [];

        foreach ($plannedOrders as $order) {
            $animal = $order->animal;

            if (!$animal) {
                continue;
            }

            if (empty($animal->last_order)) {
                continue;
            }

            $diskPath = !empty($animal->last_order_qr_code_image)
                ? storage_path('app/public/' . $animal->last_order_qr_code_image)
                : null;

            if ($diskPath && file_exists($diskPath)) {
                $qrAnimals[] = [
                    'name' => $animal->name,
                    'disk_path' => $diskPath,
                ];
            }
        }

        $qrAnimals = collect($qrAnimals)
            ->unique(function ($item) {
                return ($item['name'] ?? '') . '|' . ($item['disk_path'] ?? '');
            })
            ->values()
            ->all();

        $customerName = trim(($subscription->subscriber_first_name ?? '') . ' ' . ($subscription->subscriber_last_name ?? ''));
        if ($customerName === '') {
            $customerName = $subscription->subscriber_first_name ?? 'Customer';
        }

        $template = $settings->last_order_subscription_message ?? '';

        if (trim(strip_tags($template)) === '') {
            $template = '
                <p>عزيزنا [customer_name]،</p>
                <p>يمكنك تجديد اشتراكك عن طريق مسح رمز QR التالي:</p>
                <p>[qr_code]</p>
            ';
        }

        $qrHtml = '';
        foreach ($qrAnimals as $qrAnimal) {
            $qrHtml .= '<div style="text-align:center; margin:18px 0;">';
            $qrHtml .= '<img src="' . $qrAnimal['disk_path'] . '" style="width:180px; height:auto;">';
            $qrHtml .= '</div>';
        }

        $renderedTemplate = str_replace(
            ['[customer_name]', '[qr_code]'],
            [e($customerName), $qrHtml],
            $template
        );

        $html = view('daily-report.last-order-pdf', [
            'subscription' => $subscription,
            'date' => $date,
            'settings' => $settings,
            'customerName' => $customerName,
            'renderedTemplate' => $renderedTemplate,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'default_font' => 'dejavusans',
            'directionality' => (($settings->pdf_lang ?? 'en') === 'ar') ? 'rtl' : 'ltr',
        ]);

        $mpdf->WriteHTML($html);

        return response(
            $mpdf->Output('', 'S'),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="subscription-renewal-' . $subscription->code . '-' . $date . '.pdf"',
            ]
        );
    }

    private function computeFirstDeliveryDate(Subscription $subscription): ?Carbon
    {
        $first = null;

        foreach ($subscription->animals as $animal) {
            $start = $animal->subscription_start ? $animal->subscription_start->copy()->startOfDay() : null;
            $end   = $animal->subscription_end ? $animal->subscription_end->copy()->endOfDay() : null;

            if (!$start && !empty($subscription->creation_date)) {
                $start = Carbon::parse($subscription->creation_date)->startOfDay();
            }

            if (!$start) {
                continue;
            }

            $validMeals = $animal->meals->filter(function ($meal) {
                return $meal->quantity > 0 && $meal->recipe;
            });

            if ($validMeals->isEmpty()) {
                continue;
            }

            $days = $validMeals->pluck('day_of_week')
                ->map(fn ($d) => strtolower((string) $d))
                ->unique();

            foreach ($days as $dow) {
                $d = $start->copy();

                for ($i = 0; $i < 7; $i++) {
                    if (strtolower($d->format('l')) === $dow) {
                        break;
                    }
                    $d->addDay();
                }

                if ($end && $d->gt($end)) {
                    continue;
                }

                if (!$first || $d->lt($first)) {
                    $first = $d->copy();
                }
            }
        }

        return $first;
    }
}