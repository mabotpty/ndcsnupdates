<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportGroup extends Model
{
    use TouchesSite;

    protected $fillable = ['title', 'sort'];

    public function entries(): HasMany
    {
        return $this->hasMany(ReportEntry::class)->orderBy('sort')->orderBy('id');
    }
}
