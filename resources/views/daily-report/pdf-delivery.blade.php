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

    $getRecipeName = function($meal) {
      if (is_array($meal)) {
        if (isset($meal['recipe']) && is_object($meal['recipe']) && !empty($meal['recipe']->name)) return $meal['recipe']->name;
        if (isset($meal['recipe_name']) && $meal['recipe_name']) return $meal['recipe_name'];
        if (isset($meal['recipe']) && is_array($meal['recipe']) && !empty($meal['recipe']['name'])) return $meal['recipe']['name'];
        return '';
      }

      if (is_object($meal)) {
        if (isset($meal->recipe) && is_object($meal->recipe) && !empty($meal->recipe->name)) return $meal->recipe->name;
        if (isset($meal->recipe_name) && $meal->recipe_name) return $meal->recipe_name;
        return '';
      }

      return '';
    };

    $getQty = function($meal) {
      if (is_array($meal)) {
        if (isset($meal['quantity'])) return (int)$meal['quantity'];
        if (isset($meal['qty'])) return (int)$meal['qty'];
        return 0;
      }

      if (is_object($meal)) {
        if (isset($meal->quantity)) return (int)$meal->quantity;
        if (isset($meal->qty)) return (int)$meal->qty;
        return 0;
      }

      return 0;
    };
  @endphp

  <title>Delivery {{ $subscription->code }} - {{ $date }}</title>

  <style>
    body{
      font-family: "DejaVu Sans", sans-serif;
      font-size: 12px;
      direction: {{ $dir }};
      text-align: {{ $align }};
    }

    .header { text-align: center; margin-bottom: 12px; }
    .section { margin-bottom: 12px; }
    h1, h2, h3 { margin: 0 0 6px 0; }

    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4px; border: 1px solid #ccc; }

    .line { margin: 2px 0; }

    .instructions{
      margin-top: 8px;
      padding: 8px;
      border: 1px dashed #bbb;
      background: #fafafa;
    }
    .instructions .title{ font-weight: bold; margin-bottom: 4px; }
  </style>
</head>

<body>
  <div class="header">
    @php
      $logoDiskPath = !empty($settings->logo)
        ? storage_path('app/public/' . $settings->logo)
        : null;
    @endphp

    @if($logoDiskPath && file_exists($logoDiskPath))
      <div style="text-align:center; margin-bottom:10px;">
        <img src="{{ $logoDiskPath }}" style="height:120px; width:auto;">
      </div>
    @endif

    <h2>{{ $t('Daily Delivery Sheet', 'تفاصيل التوصيل') }}</h2>
    <p class="line"><strong>{{ $t('Date:', 'التاريخ:') }}</strong> {{ $date }}</p>
  </div>

  <div class="section">
    <h3>{{ $t('Subscriber', 'العميل') }}</h3>

    <p class="line">
      @if(!empty($subscription->subscriber_phone))
        <strong>{{ $t('Phone:', 'الهاتف:') }}</strong> {{ $subscription->subscriber_phone }}
      @endif

      @if(!empty($subscription->subscriber_delivery_slot))
        @if(!empty($subscription->subscriber_phone)) &nbsp; | &nbsp; @endif
        <strong>{{ $t('Delivery Time Slot:', 'فترة التوصيل:') }}</strong> {{ $subscription->subscriber_delivery_slot }}
      @endif

      @if(!empty($subscription->subscriber_phone) || !empty($subscription->subscriber_delivery_slot))
        &nbsp; | &nbsp;
      @endif

      <strong>{{ $t('Name:', 'الاسم:') }}</strong> {{ $subscription->subscriber_first_name }} {{ $subscription->subscriber_last_name }}
    </p>

    <p class="line">
      <strong>{{ $t('Address:', 'العنوان:') }}</strong> {{ $subscription->subscriber_address }}
      @if(!empty($subscription->subscriber_province))
        &nbsp; | &nbsp; <strong>{{ $t('Province:', 'المدينة/المحافظة:') }}</strong> {{ $subscription->subscriber_province }}
      @endif
      @if(!empty($subscription->subscriber_zone))
        &nbsp; | &nbsp; <strong>{{ $t('Zone:', 'المنطقة:') }}</strong> {{ $subscription->subscriber_zone }}
      @endif
    </p>

    @if(!empty($subscription->subscriber_note))
      <p class="line"><strong>{{ $t('Note:', 'ملاحظة:') }}</strong> {{ $subscription->subscriber_note }}</p>
    @endif
  </div>

  @foreach(($animalsForDay ?? []) as $block)
    @php
      $animal = is_array($block) ? ($block['animal'] ?? null) : ($block->animal ?? null);
      $meals = is_array($block) ? ($block['meals'] ?? []) : ($block->meals ?? []);
      if ($meals instanceof \Illuminate\Support\Collection) $meals = $meals->all();
      if (!is_array($meals)) $meals = [];
    @endphp

    @if($animal)
      <div class="section">
        <h3>{{ $t('Name:', 'الاسم:') }} {{ $animal->name }}</h3>

        <p class="line">
          @if(!empty($animal->species))
            <strong>{{ $t('Species:', 'النوع:') }}</strong> {{ $animal->species }}
          @endif

          @if(!empty($animal->age))
            @if(!empty($animal->species)) &nbsp; | &nbsp; @endif
            <strong>{{ $t('Age:', 'العمر:') }}</strong> {{ $animal->age }}
          @endif

          @if(!empty($animal->health_status))
            @if(!empty($animal->species) || !empty($animal->age)) &nbsp; | &nbsp; @endif
            <strong>{{ $t('Health:', 'الحالة الصحية:') }}</strong> {{ $animal->health_status }}
          @endif

          @if(!empty($animal->note))
            @if(!empty($animal->species) || !empty($animal->age) || !empty($animal->health_status)) &nbsp; | &nbsp; @endif
            <strong>{{ $t('Note:', 'ملاحظة:') }}</strong> {{ $animal->note }}
          @endif
        </p>

        <table>
          <thead>
            <tr>
              <th>{{ $t('Recipe', 'الوصفة') }}</th>
              <th style="width:90px;">{{ $t('Qty', 'الكمية') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($meals as $meal)
              @php
                $recipeName = $getRecipeName($meal);
                $qty = $getQty($meal);
              @endphp

              @if($recipeName !== '' && $qty > 0)
                <tr>
                  <td>{{ $recipeName }}</td>
                  <td>{{ $qty }}</td>
                </tr>
              @endif
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  @endforeach

  @if(!empty($settings->meal_storage_instructions))
    <div class="instructions">
      <div class="title">{{ $t('Meal Storage Instructions', 'إرشادات حفظ الوجبات') }}</div>
      <div>{!! $settings->meal_storage_instructions !!}</div>
    </div>
  @endif

</body>
</html>