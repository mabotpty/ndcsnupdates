<?php

namespace App\Models\Concerns;

use App\Models\Setting;

/**
 * Any change to public content bumps the "last updated" stamp shown on the
 * public pages (the old static page used the file's modified time for this).
 */
trait TouchesSite
{
    protected static function bootTouchesSite(): void
    {
        $touch = fn () => Setting::put('content_updated_at', now()->toIso8601String());

        static::saved($touch);
        static::deleted($touch);
    }
}
