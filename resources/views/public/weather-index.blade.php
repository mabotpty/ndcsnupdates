@extends('layouts.public', ['title' => 'Weather Alerts', 'subtitle' => 'Weather Alerts - North Durban and Surrounds'])

@section('content')
  <div class="notice" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
    <div>
      <p>Weather warning levels follow the South African Weather Service (SAWS) warning key. Always check the SAWS website for official warnings.</p>
    </div>
  </div>

  <span class="col-head ch-primary">Active weather alerts @if ($warnings->count())<span class="count">{{ $warnings->count() }}</span>@endif</span>

  <div class="wx-list">
    @forelse ($warnings as $w)
      @include('public._weather_detail', ['w' => $w, 'level' => $weatherLevels->firstWhere('level', (int) $w->weather_level)])
    @empty
      <div class="empty">No active weather alerts at this time.</div>
    @endforelse
  </div>
@endsection
