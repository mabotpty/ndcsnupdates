{{-- Full weather warning card. Expects $w (Incident) and $level (WeatherLevel|null). --}}
@php($phase = $w->status === 'resolved' ? 'resolved' : $w->weatherPhase())
<article class="card wx-detail lv-{{ $level?->colour ?? 'yellow' }}" id="warning-{{ $w->id }}">
  <div class="wx-top">
    <span class="wx-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 5.5 17"/><path d="m13 15-2 4h3l-2 4"/></svg> Weather warning</span>
    <span class="badge {{ ['upcoming' => 'b-info', 'active' => 'b-danger', 'expired' => 'b-warning', 'resolved' => 'b-success'][$phase] }}">{{ ['upcoming' => 'Upcoming', 'active' => 'Active', 'expired' => 'Expired', 'resolved' => 'Resolved'][$phase] }}</span>
  </div>

  <h2 class="wx-title">{{ $w->title }}</h2>

  <div class="wx-levelbox">
    <span class="level-pill"><i></i>{{ $w->weatherLevelLabel() }}</span>
    @if ($level)<p><b>{{ $level->name }}</b> &mdash; {{ $level->description }}</p>@endif
  </div>

  <dl class="wx-facts">
    <div><dt>Type of weather</dt><dd>{{ $w->weather_kind }}</dd></div>
    <div><dt>Area</dt><dd>{{ $w->area ?: '—' }}</dd></div>
    <div><dt>From</dt><dd>{{ $w->weather_from?->format('l j F Y, H:i') }}</dd></div>
    <div><dt>To</dt><dd>{{ $w->weather_to?->format('l j F Y, H:i') }}</dd></div>
    <div><dt>Issued</dt><dd>{{ $w->published_at->format('l j F Y, H:i') }}</dd></div>
    @if ($w->status === 'resolved')<div><dt>Resolved</dt><dd>{{ $w->resolved_at->format('l j F Y, H:i') }}</dd></div>@endif
  </dl>

  @if ($w->narrative)
    <h3 class="wx-sub">Details</h3>
    <div class="wx-narrative">{!! nl2br(e($w->narrative)) !!}</div>
  @endif
</article>
