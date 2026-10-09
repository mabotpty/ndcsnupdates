<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    use TouchesSite;

    protected $fillable = ['title', 'source_name', 'source_url', 'published_at', 'body'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
