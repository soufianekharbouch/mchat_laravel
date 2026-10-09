<!DOCTYPE html>
<html lang="{{ ($settings->pdf_lang ?? 'en') }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>

    @php
        $isRTL = (($settings->pdf_lang ?? 'en') === 'ar');
        $dir = $isRTL ? 'rtl' : 'ltr';
        $align = $isRTL ? 'right' : 'left';

        $t = function(string $en, string $ar) use ($isRTL) {
            return $isRTL ? $ar : $en;
        };
    @endphp

    <title>Premium Nutrition Report</title>$meal['quantity'];

    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 12px;
            color: #333;
            direction: {{ $dir }};
            text-align: {{ $align }};
        }

        .header {
            text-align: center;
            margin-bottom: 8px;
        }
        .logo {
            margin-bottom: 6px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
            color: #444;
            margin-bottom: 4px;
        }

        .section {
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 6px;
            color: #6c5ce7;
        }

        .page-break {
            page-break-before: always;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        table th, table td {
            border: 1px solid #ddd;
            padding: 6px;
            font-size: 11px;
            vertical-align: top;
        }
        table th {
            background: #f5f5f5;
        }

        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }

        .badge {
            background: #f9d976;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }

        .muted {
            color: #777;
            font-size: 11px;
        }
        .line {
            margin: 2px 0;
        }

        .note-line{
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed #ddd;
        }

        .instructions{
            margin-top: 6px;
            padding: 5px 6px;
            border: 1px dashed #ccc;
            background: #fafafa;
            font-size: 10.5px;
            line-height: 1.1;
        }

        .instructions .title{
            font-weight: bold;
            margin-bottom: 2px;
            font-size: 10.5px;
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
            <img src="{{ $logoDiskPath }}" alt="Logo Premium" style="height:110px; width:auto;">
        </div>
    @endif

    <div class="title">{{ $t('Premium Nutrition Report', 'تقرير التغذية المميزة') }}</div>
    <div class="line"><strong>{{ $t('Date:', 'التاريخ:') }}</strong> {{ $date }}</div>
</div>

<div class="section">
    <div class="section-title">{{ $t('Subscriber Information', 'معلومات العميل') }}</div>

    <p class="line">
        @if(!empty($subscription->subscriber_phone))
            <strong>{{ $t('Phone:', 'الهاتف:') }}</strong> {{ $subscription->subscriber_phone }}
        @endif
    </p>

    <p class="line">
        <strong>{{ $t('Name:', 'الاسم:') }}</strong>
        {{ $subscription->subscriber_first_name }} {{ $subscription->subscriber_last_name }}
    </p>

    <p class="line">
        <strong>{{ $t('Address:', 'العنوان:') }}</strong> {{ $subscription->subscriber_address }}
    </p>

    <p class="line">
        @if(!empty($subscription->subscriber_province))
            <strong>{{ $t('Province:', 'المدينة/المحافظة:') }}</strong> {{ $subscription->subscriber_province }}
        @endif

        @if(!empty($subscription->subscriber_zone))
            @if(!empty($subscription->subscriber_province)) &nbsp; | &nbsp; @endif
            <strong>{{ $t('Zone:', 'المنطقة:') }}</strong> {{ $subscription->subscriber_zone }}
        @endif
    </p>

    @if(!empty($subscription->subscriber_note))
        <p class="line note-line">
            <strong>{{ $t('Note:', 'ملاحظة:') }}</strong>
            {{ $subscription->subscriber_note }}
        </p>
    @endif
</div>

@php
    $hasPremiumData = false;
    $printedAnyAnimal = false;
@endphp

@foreach(($animalsForDay ?? []) as $group)
    @php
        $animal = is_array($group) ? ($group['animal'] ?? null) : ($group->animal ?? null);
        $meals = is_array($group) ? ($group['meals'] ?? []) : ($group->meals ?? []);
        if ($meals instanceof \Illuminate\Support\Collection) $meals = $meals->all();
        if (!is_array($meals)) $meals = [];
    @endphp

    @if($animal && count($meals))
        @php $hasPremiumData = true; @endphp

        <div class="section {{ $printedAnyAnimal ? '' : '' }}">
            @php $printedAnyAnimal = true; @endphp

            <div class="section-title">
                {{ $t('Animal:', 'الحيوان:') }} {{ $animal->name }}
                <span class="badge">{{ $t('Premium', 'مميز') }}</span>
            </div>

            <p class="line muted">
                @if(!empty($animal->species))
                    <strong>{{ $t('Species:', 'النوع:') }}</strong> {{ $animal->species }}
                @endif

                @if(!empty($animal->age))
                    @if(!empty($animal->species)) &nbsp; | &nbsp; @endif
                    <strong>{{ $t('Age:', 'العمر:') }}</strong> {{ $animal->age }}
                    @if(!$isRTL) years @endif
                @endif

                @if(!empty($animal->health_status))
                    @if(!empty($animal->species) || !empty($animal->age)) &nbsp; | &nbsp; @endif
                    <strong>{{ $t('Health:', 'الحالة الصحية:') }}</strong> {{ $animal->health_status }}
                @endif
            </p>

            @if(!empty($animal->note))
                <p class="line">
                    <strong>{{ $t('Note:', 'ملاحظة:') }}</strong> {{ $animal->note }}
                </p>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>{{ $t('Recipe', 'الوصفة') }}</th>
                        <th style="width:70px;">{{ $t('Qty', 'الكمية') }}</th>
                        <th style="width:220px;">{{ $t('Ingredients', 'المكونات') }}</th>
                        <th>{{ $t('Premium Composition', 'القيمة الغذائية') }}</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($meals as $meal)
                        @php
                            $recipe = is_object($meal) ? ($meal->recipe ?? null) : (is_array($meal) ? ($meal['recipe'] ?? null) : null);
                            if (is_array($recipe)) $recipe = (object)$recipe;

                            $recipeName = $recipe->name ?? '';
                            $qty = 0;

                            if (is_object($meal) && isset($meal->quantity)) $qty = (int)$meal->quantity;
                            elseif (is_array($meal) && isset($meal['quantity'])) $qty = (int)$meal['quantity'];

                            $rawComposition = $recipe->composition ?? [];
                            $composition = is_array($rawComposition)
                                ? $rawComposition
                                : json_decode($rawComposition ?: '[]', true);

                            if (!is_array($composition)) $composition = [];

                            $ingredientsList = [];

                            if (!empty($recipe) && !empty($recipe->ingredients)) {
                                foreach ($recipe->ingredients as $ri) {
                                    if (is_object($ri) && !empty($ri->ingredient) && !empty($ri->ingredient->name)) {
                                        $ingredientsList[] = $ri->ingredient->name;
                                    } elseif (is_array($ri) && !empty($ri['ingredient']['name'])) {
                                        $ingredientsList[] = $ri['ingredient']['name'];
                                    }
                                }
                            }

                            $ingredientsList = array_values(array_unique(array_filter($ingredientsList)));
                        @endphp

                        @if($recipeName !== '' && $qty > 0)
                            <tr>
                                <td>{{ $recipeName }}</td>
                                <td>{{ $qty }}</td>

                                <td>
                                    @if(count($ingredientsList))
                                        {{ implode('، ', $ingredientsList) }}
                                    @else
                                        <span class="muted">{{ $t('No ingredients', 'لا توجد مكونات') }}</span>
                                    @endif
                                </td>

                                <td>
                                    @if(count($composition))
                                        @foreach($composition as $comp)
                                            • {{ $comp['label'] ?? '' }}: {{ $comp['value'] ?? '' }}<br>
                                        @endforeach
                                    @else
                                        <span class="muted">{{ $t('No composition data', 'لا توجد بيانات') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>

        </div>
    @endif
@endforeach

@if(!$hasPremiumData)
    <div class="section">
        <p class="muted">
            {{ $t('No premium recipes found for this subscription on', 'لم يتم العثور على وصفات مميزة لهذا الاشتراك بتاريخ') }}
            {{ $date }}.
        </p>
    </div>
@endif

@if(!empty($settings->meal_storage_instructions))
    <div class="instructions">
        <div class="title">{{ $t('Meal Storage Instructions', 'إرشادات حفظ الوجبات') }}</div>
        <div>{!! $settings->meal_storage_instructions !!}</div>
    </div>
@endif

</body>
</html>
