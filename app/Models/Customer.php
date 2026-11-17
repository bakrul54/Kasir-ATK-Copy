<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'phone'])]
class Customer extends Model
{
    public const LOYAL_SALE_THRESHOLD = 10;

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function whatsappUrl(): ?string
    {
        if (! $this->phone) {
            return null;
        }

        $number = preg_replace('/\D+/', '', $this->phone) ?? '';
        $number = Str::startsWith($number, '0') ? '62'.substr($number, 1) : $number;

        return $number === '' ? null : "https://wa.me/{$number}";
    }
}
