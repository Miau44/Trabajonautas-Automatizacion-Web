<?php

namespace App\Models;

use App\Support\StoragePath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    // Permissions
    public $guarded = [];

    protected $casts = [
        'site_company_id' => 'integer',
    ];

    public function getCompanyImageAttribute(?string $value): ?string
    {
        return StoragePath::normalizePublicPath($value);
    }

    public function hasCompanyImageFile(): bool
    {
        return StoragePath::exists($this->company_image);
    }

    public function companyImageUrl(): ?string
    {
        return StoragePath::url($this->company_image);
    }

    // Relationships
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }
}
