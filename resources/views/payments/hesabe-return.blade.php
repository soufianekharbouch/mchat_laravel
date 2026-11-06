<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maison Chat</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: -apple-system, "Segoe UI", Tahoma, sans-serif; background: #F7F4EF; color: #2b2b2b; }
        .box { width: 88%; max-width: 380px; text-align: center; padding: 28px 22px; background: #fff;
               border-radius: 18px; box-shadow: 0 6px 24px rgba(0,0,0,.08); }
        .icon { width: 72px; height: 72px; margin: 0 auto 14px; border-radius: 50%; display: flex;
                align-items: center; justify-content: center; font-size: 38px; color: #fff; }
        .paid { background: #22B1B3; } .pending { background: #E0A100; } .failed { background: #D9534F; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p { font-size: 14px; color: #777; margin: 4px 0; }
        a.btn { display: block; margin-top: 20px; padding: 14px; border-radius: 12px; background: #22B1B3;
                color: #fff; text-decoration: none; font-weight: bold; }
        .small { font-size: 12px; color: #aaa; margin-top: 12px; }
    </style>
</head>
<body>
<div class="box">
    @if($status === 'paid')
        <div class="icon paid">✓</div>
        <h1>تم الدفع بنجاح · Payment successful</h1>
    @elseif($status === 'pending')
        <div class="icon pending">…</div>
        <h1>جارٍ تأكيد الدفع · Confirming the payment</h1>
    @else
        <div class="icon failed">✕</div>
        <h1>لم يتم الدفع · Payment not completed</h1>
    @endif

    @if($amount)
        <p>{{ $amount }}</p>
    @endif

    <a class="btn" href="{{ $appUrl }}">العودة إلى التطبيق · Back to the app</a>
    <div class="small">{{ $reference }}</div>
</div>

<script>
    // Back to the app at once (the button stays if the browser blocks it)
    setTimeout(function () { window.location.href = @json($appUrl); }, 50);
</script>
</body>
</html>
