<?php

namespace App\Models;

use App\Models\Concerns\TouchesSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One road (or place) in a situation report section, with its area and a traffic-light status. */
class ReportEntry extends Model
{
    use TouchesSite;

    public const STATUSES = ['ok' => 'All clear', 'warning' => 'Caution', 'danger' => 'Avoid'];

    public const DOTS = ['ok' => '🟢', 'warning' => '🟠', 'danger' => '🔴'];

    protected $fillable = ['report_group_id', 'road', 'area', 'status', 'sort'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class, 'report_group_id');
    }

    public function dot(): string
    {
        return self::DOTS[$this->status] ?? '⚪';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** "Road Name – Area" */
    public function label(): string
    {
        return $this->road.($this->area ? ' – '.$this->area : '');
    }
}
