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

  <title>Daily Report - {{ $date }}</title>

  <style>
    body{
      font-family: "DejaVu Sans", sans-serif;
      font-size: 11px;
      direction: {{ $dir }};
      text-align: {{ $align }};
    }
    .header { text-align: center; margin-bottom: 12px; }
    .section { margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 5px; border: 1px solid #ccc; vertical-align: top; }
    h2, h3 { margin: 0 0 8px 0; }
    .muted { color: #666; font-size: 10px; }
    .badge { display:inline-block; padding:2px 6px; border:1px solid #bbb; border-radius:3px; }
    .block { padding:8px; border:1px solid #ddd; background:#fafafa; }
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
        <img src="{{ $logoDiskPath }}" style="height:80px; width:auto;">
      </div>
    @endif

    <h2>{{ $t('Daily Preparation Report', 'تقرير التحضير اليومي') }}</h2>
    <div><strong>{{ $t('Date:', 'التاريخ:') }}</strong> {{ $date }}</div>
    <div class="muted">
      <strong>{{ $t('Total deliveries:', 'إجمالي التوصيلات:') }}</strong> {{ $subscriptions->count() }}
    </div>
  </div>

  {{-- Recipes Summary --}}
  <div class="section">
    <h3>{{ $t('Recipes Summary', 'ملخص الوصفات') }}</h3>

    <table>
      <thead>
        <tr>
          <th>{{ $t('Recipe', 'الوصفة') }}</th>
          <th style="width:120px;">{{ $t('Total Qty', 'الكمية الإجمالية') }}</th>
        </tr>
      </thead>
      <tbody>
        @forelse($recipeCounts as $recipeId => $count)
          <tr>
            <td>{{ $recipes[$recipeId]->name ?? 'Unknown Recipe' }}</td>
            <td>{{ $count }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="2">{{ $t('No recipes for this date.', 'لا توجد وصفات لهذا التاريخ.') }}</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- Ingredients Required By Recipe Type --}}
  <div class="section">
    <h3>{{ $t('Ingredients Required By Recipe Type (Raw quantity before preparation)', 'المكونات المطلوبة حسب نوع الوصفة') }}</h3>

    @if(empty($ingredientPerRecipe))
      <div class="block">{{ $t('No ingredients for the selected date.', 'لا توجد مكونات لهذا التاريخ.') }}</div>
    @else

      @php
        $premiumRows = [];
        $normalPerType = [];

        foreach ($ingredientPerRecipe as $recipeId => $ingredientsForRecipe) {
          $recipe = $recipes[$recipeId] ?? null;
          if (!$recipe) continue;

          $qtyRecipe = (int)($recipeCounts[$recipeId] ?? 0);

          if (!empty($recipe->is_premium)) {
            $premiumRows[] = [
              'recipe' => $recipe,
              'qty' => $qtyRecipe,
              'ingredients' => $ingredientsForRecipe,
            ];
            continue;
          }

          $type = $recipe->type ?? null;
          $typeKey = $type ? $type->id : 'no_type';

          if (!isset($normalPerType[$typeKey])) {
            $normalPerType[$typeKey] = [
              'type' => $type,
              'recipes' => [],
            ];
          }

          $normalPerType[$typeKey]['recipes'][$recipeId] = $ingredientsForRecipe;
        }

        usort($premiumRows, function($a, $b) {
          return strcmp($a['recipe']->name ?? '', $b['recipe']->name ?? '');
        });
      @endphp

      @if(!empty($premiumRows))
        <div class="block" style="border-color:#f0c36d; background:#fff7e6; margin-bottom:10px;">
          <strong>{{ $t('Premium recipes:', 'وصفات بريميوم:') }}</strong>
          {{ count($premiumRows) }}
        </div>
      @endif

      @foreach($normalPerType as $typeKey => $typeData)
        @php
          $type = $typeData['type'];
          $recipesForType = $typeData['recipes'];

          $allIngredients = [];
          $perRecipeIngredients = [];
          $ingredientTotals = [];

          foreach ($recipesForType as $recipeId => $ingredientsForRecipe) {
            foreach ($ingredientsForRecipe as $ingredientData) {
              $ingredient = $ingredientData['ingredient'];
              $qty = $ingredientData['total_quantity'];
              $ingredientId = $ingredient->id;

              if (!isset($allIngredients[$ingredientId])) {
                $allIngredients[$ingredientId] = $ingredient;
                $ingredientTotals[$ingredientId] = 0;
              }

              if (!isset($perRecipeIngredients[$recipeId])) $perRecipeIngredients[$recipeId] = [];
              if (!isset($perRecipeIngredients[$recipeId][$ingredientId])) $perRecipeIngredients[$recipeId][$ingredientId] = 0;

              $perRecipeIngredients[$recipeId][$ingredientId] += $qty;
              $ingredientTotals[$ingredientId] += $qty;
            }
          }

          ksort($perRecipeIngredients);
        @endphp

        <h3 style="margin:12px 0 6px 0;">
          {{ $type ? $type->name : $t('Other recipes', 'وصفات أخرى') }}
        </h3>

        <table>
          <thead>
            <tr>
              <th style="width:170px;">{{ $t('Recipe', 'الوصفة') }}</th>
              <th style="width:60px; text-align:center;">{{ $t('Qty', 'الكمية') }}</th>

              @foreach($allIngredients as $ingredientId => $ingredient)
                <th style="text-align:center;">
                  {{ $ingredient->name }}<br>
                  <small>({{ $ingredient->unit }})</small>
                  @if($ingredient->yield_percentage !== null)
                    <br>
                    <small>{{ $t('Yield:', 'المردود:') }} {{ rtrim(rtrim(number_format($ingredient->yield_percentage, 2, '.', ''), '0'), '.') }}%</small>
                  @endif
                </th>
              @endforeach
            </tr>
          </thead>

          <tbody>
            @foreach($perRecipeIngredients as $recipeId => $ingredientsMap)
              @php
                $recipe = $recipes[$recipeId] ?? null;
                $recipeName = $recipe ? $recipe->name : ('Recipe #'.$recipeId);
                $qtyRecipe = $recipeCounts[$recipeId] ?? 0;
              @endphp

              <tr>
                <td><strong>{{ $recipeName }}</strong></td>
                <td style="text-align:center;"><strong>{{ $qtyRecipe }}</strong></td>

                @foreach($allIngredients as $ingredientId => $ingredient)
                  @php $val = $ingredientsMap[$ingredientId] ?? 0; @endphp
                  <td style="text-align:right;">
                    {{ $val > 0 ? number_format($val, 2) : '-' }}
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>

          <tfoot>
            <tr>
              <td colspan="2" style="text-align:right;"><strong>{{ $t('Total raw', 'الإجمالي الخام') }}</strong></td>
              @foreach($allIngredients as $ingredientId => $ingredient)
                @php $totalVal = $ingredientTotals[$ingredientId] ?? 0; @endphp
                <td style="text-align:right;"><strong>{{ number_format($totalVal, 2) }}</strong></td>
              @endforeach
            </tr>
          </tfoot>
        </table>
      @endforeach

      @if(!empty($premiumRows))
        <h3 style="margin:12px 0 6px 0;">{{ $t('Premium recipes details', 'تفاصيل وصفات بريميوم') }}</h3>

        @php
          $premiumAllIngredients = [];
          $premiumPerRecipe = [];
          $premiumTotals = [];

          foreach ($premiumRows as $row) {
            $recipe = $row['recipe'];
            $rid = (int)$recipe->id;

            foreach (($row['ingredients'] ?? []) as $ingredientData) {
              $ingredient = $ingredientData['ingredient'];
              $qty = (float)$ingredientData['total_quantity'];
              $iid = (int)$ingredient->id;

              if (!isset($premiumAllIngredients[$iid])) {
                $premiumAllIngredients[$iid] = $ingredient;
                $premiumTotals[$iid] = 0;
              }

              if (!isset($premiumPerRecipe[$rid])) $premiumPerRecipe[$rid] = [];
              if (!isset($premiumPerRecipe[$rid][$iid])) $premiumPerRecipe[$rid][$iid] = 0;

              $premiumPerRecipe[$rid][$iid] += $qty;
              $premiumTotals[$iid] += $qty;
            }
          }

          ksort($premiumPerRecipe);
        @endphp

        <table>
          <thead>
            <tr>
              <th style="width:170px;">{{ $t('Recipe', 'الوصفة') }}</th>
              <th style="width:60px; text-align:center;">{{ $t('Qty', 'الكمية') }}</th>

              @foreach($premiumAllIngredients as $iid => $ingredient)
                <th style="text-align:center;">
                  {{ $ingredient->name }}<br>
                  <small>({{ $ingredient->unit }})</small>
                  @if($ingredient->yield_percentage !== null)
                    <br>
                    <small>{{ $t('Yield:', 'المردود:') }} {{ rtrim(rtrim(number_format($ingredient->yield_percentage, 2, '.', ''), '0'), '.') }}%</small>
                  @endif
                </th>
              @endforeach
            </tr>
          </thead>

          <tbody>
            @foreach($premiumPerRecipe as $rid => $ingredientsMap)
              @php
                $recipe = $recipes[$rid] ?? null;
                $recipeName = $recipe ? $recipe->name : ('Recipe #'.$rid);
                $qtyRecipe = (int)($recipeCounts[$rid] ?? 0);
              @endphp

              <tr>
                <td><strong>{{ $recipeName }}</strong></td>
                <td style="text-align:center;"><strong>{{ $qtyRecipe }}</strong></td>

                @foreach($premiumAllIngredients as $iid => $ingredient)
                  @php $val = $ingredientsMap[$iid] ?? 0; @endphp
                  <td style="text-align:right;">
                    {{ $val > 0 ? number_format($val, 2) : '-' }}
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>

          <tfoot>
            <tr>
              <td colspan="2" style="text-align:right;"><strong>{{ $t('Total raw', 'الإجمالي الخام') }}</strong></td>
              @foreach($premiumAllIngredients as $iid => $ingredient)
                @php $totalVal = $premiumTotals[$iid] ?? 0; @endphp
                <td style="text-align:right;"><strong>{{ number_format($totalVal, 2) }}</strong></td>
              @endforeach
            </tr>
          </tfoot>
        </table>
      @endif

    @endif
  </div>

  {{-- Deliveries (Grouped by Province) --}}
  <div class="section">
    <h3>{{ $t('Deliveries', 'التوصيلات') }}</h3>

    @php
      $groupedByProvince = $subscriptions->groupBy(function($sub){
        $prov = trim((string)($sub->subscriber_province ?? ''));
        return $prov !== '' ? $prov : 'Unknown province';
      })->sortKeys();
    @endphp

    @foreach($groupedByProvince as $province => $subs)
      <h3 style="margin-top:12px;">{{ $province }} <span class="badge">{{ $subs->count() }}</span></h3>

      <table>
        <thead>
          <tr>
            <th style="width:180px;">{{ $t('Subscriber', 'العميل') }}</th>
            <th>{{ $t('Address', 'العنوان') }}</th>
            <th style="width:150px;">{{ $t('Phone / Code', 'الهاتف / الكود') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($subs as $s)
            @php
              $zone = trim((string)($s->subscriber_zone ?? ''));
              $zone = $zone !== '' ? $zone : $t('Unknown zone', 'منطقة غير معروفة');
              $phone = $s->subscriber_phone ?? '-';
              $code = $s->code ?? '-';
            @endphp
            <tr>
              <td><strong>{{ $s->subscriber_first_name }} {{ $s->subscriber_last_name }}</strong></td>
              <td>
                {{ $s->subscriber_address }}
                <strong> - {{ $zone }}</strong>
              </td>
              <td>
                <strong>{{ $t('Phone:', 'الهاتف:') }}</strong> {{ $phone }}<br>
                <strong>{{ $t('Code:', 'الكود:') }}</strong> {{ $code }}
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endforeach
  </div>

</body>
</html>
