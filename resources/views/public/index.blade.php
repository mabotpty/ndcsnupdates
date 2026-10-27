@extends('layouts.public')

@section('content')
  <div class="dock">
  @if ($telegramUrl)
    <div class="card join">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.9 8.2-2 9.4c-.15.67-.54.83-1.1.52l-3-2.2-1.45 1.4c-.16.16-.3.3-.6.3l.2-3.05 5.55-5c.24-.2-.05-.33-.38-.13L7.2 13.5l-2.95-.92c-.64-.2-.65-.64.13-.95l11.5-4.43c.53-.2 1 .13.82.95z"/></svg>
      <div class="join-text">
        <strong>Get updates instantly</strong>
        <span class="sub">Join our public Telegram group for live community safety updates.</span>
      </div>
      <a class="btn-tg" href="{{ $telegramUrl }}" target="_blank" rel="noopener noreferrer"><span class="long">Join on Telegram</span><span class="short">Join</span></a>
    </div>
  @endif

  <div class="card join app-bar" id="app-bar">
    <img class="app-icon" src="{{ url('/icons/icon-192.png') }}?v=2" alt="" width="34" height="34">
    <div class="join-text">
      <strong>Install it as an app</strong>
      <span class="sub">To get quick access and alerts on your device</span>
    </div>
    <button type="button" class="btn-tg btn-app" data-install><span class="long">Install app</span><span class="short">Install</span></button>
    <div class="install-help" id="install-help" hidden>
      <p data-os="ios"><b>iPhone / iPad:</b> open this page in <b>Safari</b>, tap the <b>Share</b> button, then choose <b>Add to Home Screen</b>.</p>
      <p data-os="android"><b>Android:</b> in Chrome tap the <b>⋮</b> menu, then <b>Install app</b> (or <b>Add to Home screen</b>).</p>
      <p data-os="android-other"><b>Android:</b> for a clean install, open this page in <b>Chrome</b>, then tap <b>⋮</b> → <b>Install app</b>. <a class="open-chrome" href="intent://{{ request()->getHttpHost() }}/#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url={{ urlencode(url('/')) }};end">Open in Chrome</a><br><span class="sub">Your current browser can’t install it as a proper app (Android may show an “unsafe app” warning — the app itself is safe, it’s that browser’s packaging).</span></p>
      <p data-os="desktop"><b>Computer:</b> in Chrome or Edge click the <b>install</b> icon at the right of the address bar, or open the browser menu and choose <b>Install NDCSN Updates</b>.</p>
    </div>
  </div>
  </div>

  <div class="top-row">
    <div class="top-col">
      <section class="card alert-card lv-{{ $current?->colour ?? 'green' }}" aria-labelledby="lvl-h">
        <h2 class="section-label" id="lvl-h">Current Safety Alert Level</h2>
        <span class="level-pill"><i></i>{{ $current?->name }}</span>
        <h3>{{ $current?->description }}</h3>
      </section>

      <aside class="card legend small sec collapsed" data-key="guide" data-default="collapsed" aria-label="Safety alert level guide">
        <h2 class="section-label"><button type="button" class="toggle" aria-expanded="false">Safety alert level guide <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button></h2>
        <ul class="sec-body">
          @foreach ($levels as $l)
            <li @class(['now' => $current && $l->level === $current->level])>
              <span class="sw sw-{{ $l->colour }}"></span>
              <span><b>{{ $l->name }}</b> &mdash; {{ $l->description }}</span>
            </li>
          @endforeach
        </ul>
      </aside>
    </div>

    <div class="top-col">
      <section class="card alert-card lv-{{ $weatherCurrent?->colour ?? 'green' }}" aria-labelledby="wx-h">
        <h2 class="section-label" id="wx-h">Current Weather Alert Level</h2>
        <span class="level-pill"><i></i>{{ $weatherCurrent?->name }}</span>
        <h3>{{ $weatherCurrent?->description }}</h3>
        @foreach ($weatherUpcoming as $u)
          <p class="wx-next"><a href="{{ $u->weatherUrl() }}"><b>{{ $u->weatherLevelLabel() }}</b> &mdash; {{ $u->title }}</a></p>
        @endforeach
      </section>

      <aside class="card legend small sec collapsed" data-key="wguide" data-default="collapsed" aria-label="Weather alert level guide">
        <h2 class="section-label"><button type="button" class="toggle" aria-expanded="false">Weather alert level guide <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button></h2>
        <ul class="sec-body">
          @foreach ($weatherLevels as $l)
            <li @class(['now' => $weatherCurrent && $l->level === $weatherCurrent->level])>
              <span class="sw sw-{{ $l->colour }}"></span>
              <span><b>{{ $l->name }}</b> &mdash; {{ $l->description }}</span>
            </li>
          @endforeach
        </ul>
      </aside>
    </div>
  </div>

  <div class="columns">
    {{-- Situation report --}}
    <section class="sec sitrep collapsed" data-key="sitrep" data-default="collapsed" aria-label="Situation report">
      <button type="button" class="col-head ch-primary toggle" aria-expanded="false">Situation Report <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="stack sec-body">
        @forelse ($groups as $group)
          <div class="card item">
            <h4>{{ $group->title }}</h4>
            @if ($group->entries->isEmpty())
              <p class="sit-empty"><span class="dot dot-ok" aria-hidden="true"></span> No reported issues</p>
            @else
              <ul class="sit-list">
                @foreach ($group->entries as $entry)
                  <li class="sit-item">
                    <span class="dot dot-{{ $entry->status }}" role="img" aria-label="{{ $entry->statusLabel() }}" title="{{ $entry->statusLabel() }}"></span>
                    <span class="sit-text"><b>{{ $entry->road }}</b>@if ($entry->area)<small>{{ $entry->area }}</small>@endif</span>
                    <span class="sit-state sit-{{ $entry->status }}">{{ $entry->statusLabel() }}</span>
                  </li>
                @endforeach
              </ul>
            @endif
          </div>
        @empty
          <div class="empty">No situation report at this time</div>
        @endforelse
        <p class="sit-key"><span class="dot dot-ok"></span> All clear <span class="dot dot-warning"></span> Caution <span class="dot dot-danger"></span> Avoid</p>
      </div>
    </section>

    {{-- Warnings --}}
    <section class="sec" data-key="warnings" aria-label="Warnings">
      <button type="button" class="col-head ch-danger toggle" aria-expanded="true">Warnings @if ($warnings->count())<span class="count">{{ $warnings->count() }}</span>@endif <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="stack sec-body">
        @forelse ($warnings as $i)
          @include('public._card', ['i' => $i, 'kind' => 'warning'])
        @empty
          <div class="empty">None at this time</div>
        @endforelse
      </div>
    </section>

    {{-- Monitoring --}}
    <section class="sec" data-key="monitoring" aria-label="Monitoring and minor issues">
      <button type="button" class="col-head ch-warning toggle" aria-expanded="true">Monitoring/Minor Issues @if ($monitoring->count())<span class="count">{{ $monitoring->count() }}</span>@endif <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="stack sec-body">
        @forelse ($monitoring as $i)
          @include('public._card', ['i' => $i, 'kind' => 'monitoring'])
        @empty
          <div class="empty">None reported at this time</div>
        @endforelse
      </div>
    </section>

    {{-- Resolved --}}
    <section class="sec collapsed" data-key="resolved" data-default="collapsed" aria-label="Resolved">
      <button type="button" class="col-head ch-success toggle" aria-expanded="false">Resolved @if ($resolved->count())<span class="count">{{ $resolved->count() }}</span>@endif <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="stack sec-body">
        @forelse ($resolved as $i)
          @include('public._card', ['i' => $i, 'kind' => 'resolved'])
        @empty
          <div class="empty">None reported at this time</div>
        @endforelse
      </div>
    </section>
  </div>

  <div class="notice" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
    <div>
      <p><strong>All information is acquired from local WhatsApp traffic and security groups, Ward Councillors, SAPS, Metro, eThekwini, SA Weather Service or other sources.</strong></p>
      <p>Whilst all efforts are made to ensure it is verified, accurate, and up-to-date, we cannot guarantee 100% accuracy and accept no liability for information provided.</p>
    </div>
  </div>
@endsection
