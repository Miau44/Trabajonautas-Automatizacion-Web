<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotVacancyPreview extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'raw_data' => 'array',
        'selected_profession_ids' => 'array',
        'selected_location_ids' => 'array',
        'attachments' => 'array',
        'is_pro' => 'boolean',
        'removed_from_batch_at' => 'datetime',
    ];

    public function botCompany(): BelongsTo
    {
        return $this->belongsTo(BotCompany::class);
    }

    public function selectedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'selected_company_id');
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'convocatoria_id');
    }
}
