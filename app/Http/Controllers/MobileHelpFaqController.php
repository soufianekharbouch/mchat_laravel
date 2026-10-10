<?php

namespace App\Http\Controllers;

use App\Models\HelpFaq;
use Illuminate\Http\JsonResponse;

class MobileHelpFaqController extends Controller
{
    public function index(): JsonResponse
    {
        $faqs = HelpFaq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'question_en',
                'question_ar',
                'answer_en',
                'answer_ar',
                'sort_order',
            ]);

        return response()->json([
            'success' => true,
            'data' => $faqs,
        ]);
    }
}
