<!DOCTYPE html>
<html lang="{{ ($settings->pdf_lang ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

    @php
        $isRTL = (($settings->pdf_lang ?? 'en') === 'ar');
        $dir = $isRTL ? 'rtl' : 'ltr';
        $align = $isRTL ? 'right' : 'left';

        $t = function(string $en, string $ar) use ($isRTL) {
            return $isRTL ? $ar : $en;
        };
    @endphp

    <title>Welcome - {{ $subscription->code }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color:#222;
            direction: {{ $dir }};
            text-align: {{ $align }};
        }

        .header { text-align:center; margin-bottom: 14px; }
        .logo img { height: 70px; width:auto; }

        .box { border:1px solid #ddd; border-radius:8px; padding:12px; margin-top:12px; }

        .title { font-size: 18px; margin: 0 0 8px 0; }

        .muted { color:#666; font-size: 11px; }

        .badge {
            display:inline-block;
            padding:4px 8px;
            border-radius:999px;
            background:#22c55e;
            color:#fff;
            font-size:11px;
        }

        .row { margin-top:8px; }

        /* richtext output */
        .rt-content * { margin: 0 0 8px 0; }
        .rt-content p { margin: 0 0 8px 0; }
        .rt-content ul, .rt-content ol { margin: 6px 0 6px 0; padding: 0 18px; }
        .rt-content li { margin: 0 0 6px 0; }
    </style>
</head>
<body>
    @php
        $logoDiskPath = !empty($settings->logo_premium)
            ? storage_path('app/public/' . $settings->logo_premium)
            : null;
    @endphp

    <div class="header">
        @if($logoDiskPath && file_exists($logoDiskPath))
            <div class="logo">
                <img src="{{ $logoDiskPath }}" alt="Logo Premium" style="height:120px; width:auto;">
            </div>
        @endif

        <h2 style="margin:0;">{{ $t('Welcome Pack', 'حزمة الترحيب') }}</h2>
        <div class="muted">{{ $t('Subscription:', 'الاشتراك:') }} {{ $subscription->code }}</div>
    </div>

    <div class="box">
        <div class="title">
            {{ $t('Hello', 'مرحباً') }}
            {{ $subscription->subscriber_first_name }} {{ $subscription->subscriber_last_name }}
            <span class="badge">{{ $t('New Customer', 'عميل جديد') }}</span>
        </div>

        <div class="row">
            <strong>{{ $t('Delivery date:', 'تاريخ التوصيل:') }}</strong>
            {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}
        </div>

        @if($firstDeliveryDate)
            <div class="row">
                <strong>{{ $t('First delivery date:', 'أول تاريخ للتوصيل:') }}</strong>
                {{ $firstDeliveryDate->format('M j, Y') }}
            </div>
        @endif

        <div class="row">
            <strong>{{ $t('Address:', 'العنوان:') }}</strong><br>
            {{ $subscription->subscriber_address }}<br>
            <span class="muted">{{ $subscription->subscriber_province }} - {{ $subscription->subscriber_zone }}</span>
        </div>
    </div>

    <div class="box">
        @if(!empty($settings->welcome_message))
            <div class="rt-content">
                {!! $settings->welcome_message !!}
            </div>
        @else
            <div class="rt-content">
                <p style="margin:0 0 8px 0;">
                    {{ $t(
                        "Thank you for joining our Pet Nutrition program. We’re happy to start your first delivery today.",
                        "شكراً لانضمامك إلى برنامج التغذية الخاص بنا. يسعدنا بدء أول عملية توصيل لك اليوم."
                    ) }}
                </p>
                <p style="margin:0;">
                    {{ $t(
                        "If you have any questions or need changes, please contact our team.",
                        "إذا كانت لديك أي أسئلة أو رغبت في إجراء أي تغييرات، يرجى التواصل مع فريقنا."
                    ) }}
                </p>
            </div>
        @endif
    </div>

    <div class="muted" style="text-align:center; margin-top:14px;">
        {{ $t('Generated on', 'تم الإنشاء بتاريخ') }} {{ now()->format('M j, Y H:i') }}
    </div>
</body>
</html>
