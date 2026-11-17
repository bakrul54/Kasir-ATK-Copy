<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'minimum_quantity'])]
class ProductWholesaleGroup extends Model
{
    protected function casts(): array
    {
        return [
            'minimum_quantity' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'wholesale_group_id');
    }
}
