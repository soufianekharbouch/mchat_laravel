<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpFaq extends Model
{
    protected $table = 'help_faqs';

    protected $fillable = [
        'question_en',
        'question_ar',
        'answer_en',
        'answer_ar',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
