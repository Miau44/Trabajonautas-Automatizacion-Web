<?php

return [
    'automatic_match_threshold' => (float) env('PROFESSION_AUTOMATIC_MATCH_THRESHOLD', 0.90),
    'manual_review_threshold' => (float) env('PROFESSION_MANUAL_REVIEW_THRESHOLD', 0.75),
    'dominant_area_threshold' => (float) env('PROFESSION_DOMINANT_AREA_THRESHOLD', 0.70),
    'ai_area_threshold' => (float) env('PROFESSION_AI_AREA_THRESHOLD', 0.90),
    'ambiguity_margin' => (float) env('PROFESSION_AMBIGUITY_MARGIN', 0.05),
    'affinity_expansion_excluded_area_names' => [
        'Áreas POCO FRECUENTES',
    ],
    'affinity_expansion_exclusion_anchor_professions' => [
        'Ingeniería de Alimentos',
    ],
    'classifier_version' => env('PROFESSION_CLASSIFIER_VERSION', 'profession-catalog-v6-broad-areas'),
    'prompt_version' => env('EVALUAR_PROFESSION_PROMPT_VERSION', 'evaluar-professions-v4'),
];
