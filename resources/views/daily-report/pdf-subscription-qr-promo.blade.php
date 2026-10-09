<!DOCTYPE html>
<html lang="{{ ($settings->pdf_lang ?? 'ar') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

    @php
        $isRTL = (($settings->pdf_lang ?? 'ar') === 'ar');
        $dir = $isRTL ? 'rtl' : 'ltr';
        $align = $isRTL ? 'right' : 'left';
    @endphp

    <title>Subscription QR Promo - {{ $subscription->code }}</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #222;
            direction: {{ $dir }};
            text-align: {{ $align }};
        }

        .header {
            text-align: center;
            margin-bottom: 16px;
        }

        .logo img {
            height: 90px;
            width: auto;
        }

        .box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 14px;
            margin-top: 12px;
        }

        .title {
            font-size: 18px;
            margin: 0 0 8px 0;
        }

        .muted {
            color: #666;
            font-size: 11px;
        }

        .rt-content * {
            margin: 0 0 8px 0;
        }

        .rt-content p {
            margin: 0 0 8px 0;
        }

        .rt-content ul,
        .rt-content ol {
            margin: 6px 0 6px 0;
            padding: 0 18px;
        }

        .rt-content li {
            margin: 0 0 6px 0;
        }
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
                <img src="{{ $logoDiskPath }}" alt="Logo Premium" width="200px">
            </div>
        @endif

        <h2 style="margin:0;">Subscription QR Promo</h2>
        <div class="muted">Subscription: {{ $subscription->code }}</div>
    </div>

    <div class="box">
        <div class="title">
            {{ $customerName }}
        </div>

        <div class="muted">
            {{ $subscription->subscriber_province }} - {{ $subscription->subscriber_zone }}
        </div>
    </div>

    <div class="box">
        <div class="rt-content">
            {!! $renderedTemplate !!}
        </div>
    </div>

    <div class="muted" style="text-align:center; margin-top:14px;">
        Generated on {{ now()->format('M j, Y H:i') }}
    </div>
</body>
</html>