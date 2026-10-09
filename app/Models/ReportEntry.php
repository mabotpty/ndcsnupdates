<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportEntry extends Model
{
    use TouchesSite;

    public const STATUSES = ['ok' => 'All clear', 'warning' => 'Caution', 'danger' => 'Avoid'];

    protected $fillable = ['report_group_id', 'area', 'status', 'lines', 'sort'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class, 'report_group_id');
    }

    /** @return list<string> */
    public function bullets(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $this->lines))));
    }
}
