<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Model;

class WeatherLevel extends Model
{
    use TouchesSite;

    public $timestamps = false;

    protected $primaryKey = 'level';

    public $incrementing = false;

    protected $fillable = ['level', 'name', 'colour', 'description'];
}
