{{-- One incident or weather warning card. Expects $i, $kind (warning|monitoring|resolved), $weatherLevels, $telegramUrl --}}
@php($badge = ['warning' => 'b-danger', 'monitoring' => 'b-warning', 'resolved' => 'b-success'][$kind])
@if ($i->isWeather())
  @php($wl = $weatherLevels->firstWhere('level', (int) $i->weather_level))
  <article class="card item is-{{ $kind }} is-weather">
    <div class="wx-top">
      <span class="wx-tag"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 1 0 5.5 17"/><path d="m13 15-2 4h3l-2 4"/></svg> Weather</span>
      <span class="wx-level wxc-{{ $wl?->colour ?? 'yellow' }}">Level {{ $i->weather_level }}</span>
    </div>
    <h4><a href="{{ route('weather', $i) }}">{{ $i->title }}</a></h4>
    <p class="wx-meta">{{ $i->weather_kind }}@if ($i->area) · {{ $i->area }}@endif</p>
    <p class="wx-when">{{ $i->weather_from?->format('D j M, H:i') }} &rarr; {{ $i->weather_to?->format('D j M, H:i') }}</p>
    <p><a class="wx-more" href="{{ route('weather', $i) }}">Full details &rarr;</a></p>
    <p><span class="badge {{ $badge }}">{{ $kind === 'resolved' ? 'Resolved' : 'Weather warning' }}</span></p>
    <span class="when">{{ $kind === 'resolved' ? 'Resolved '.$i->resolved_at->format('D j M, H:i') : 'Issued '.$i->published_at->format('D j M, H:i') }}</span>
  </article>
@else
  <article class="card item is-{{ $kind }}">
    <h4>{{ $i->title }}</h4>
    @if ($i->body)<p>{!! nl2br(e($i->body)) !!}</p>@endif
    @include('public._media')
    <p><span class="badge {{ $badge }}">{{ ucfirst($kind) }}</span></p>
    <span class="when">{{ $kind === 'resolved' ? 'Resolved '.$i->resolved_at->format('D j M, H:i') : 'Occurred '.$i->published_at->format('D j M, H:i') }}</span>
  </article>
@endif
