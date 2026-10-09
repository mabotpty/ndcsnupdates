@extends('layouts.public')

@section('content')
  @if ($telegramUrl)
    <div class="card join">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12s5.37 12 12 12 12-5.37 12-12S18.63 0 12 0zm5.9 8.2-2 9.4c-.15.67-.54.83-1.1.52l-3-2.2-1.45 1.4c-.16.16-.3.3-.6.3l.2-3.05 5.55-5c.24-.2-.05-.33-.38-.13L7.2 13.5l-2.95-.92c-.64-.2-.65-.64.13-.95l11.5-4.43c.53-.2 1 .13.82.95z"/></svg>
      <div class="join-text">
        <strong>Get updates instantly</strong>
        <span class="sub">Join our public Telegram group for live community safety updates.</span>
      </div>
      <a class="btn-tg" href="{{ $telegramUrl }}" target="_blank" rel="noopener noreferrer">Join on Telegram</a>
    </div>
  @endif

  <div class="notice" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
    <div>
      <p><strong>All information is acquired from local WhatsApp traffic and security groups, Ward Councillors, SAPS, Metro, eThekwini or other sources.</strong></p>
      <p>Whilst all efforts are made to ensure it is verified, accurate, and up-to-date, we cannot guarantee 100% accuracy and accept no liability for information provided.</p>
      <p>Latest releases from the SAPS can be found here: <a href="{{ route('news') }}">SAPS Releases</a></p>
    </div>
  </div>

  <div class="top-row">
    <section class="card alert-card lv-{{ $current?->colour ?? 'green' }}" aria-labelledby="lvl-h">
      <h2 class="section-label" id="lvl-h">Current Safety Alert Level</h2>
      <span class="level-pill"><i></i>{{ $current?->name }}</span>
      <h3>{{ $current?->description }}</h3>
    </section>

    <aside class="card legend" aria-label="Alert level guide">
      <h2 class="section-label">Alert level guide</h2>
      <ul>
        @foreach ($levels as $l)
          <li @class(['now' => $current && $l->level === $current->level])>
            <span class="sw sw-{{ $l->colour }}"></span>
            <span><b>{{ $l->name }}</b> &mdash; {{ $l->description }}</span>
          </li>
        @endforeach
      </ul>
    </aside>
  </div>

  <div class="columns">
    {{-- Situation report --}}
    <section class="sitrep" aria-label="Situation report">
      <span class="col-head ch-primary">Situation Report</span>
      <div class="stack">
        @forelse ($groups as $group)
          <div class="card item">
            <h4>{{ $group->title }}</h4>
            @foreach ($group->entries as $entry)
              <div class="area">
                @if ($entry->area)
                  <span class="badge b-{{ ['ok' => 'success', 'warning' => 'warning', 'danger' => 'danger'][$entry->status] ?? 'success' }}">{{ $entry->area }}</span>
                @endif
                <ul>
                  @foreach ($entry->bullets() as $line)<li>{{ $line }}</li>@endforeach
                </ul>
              </div>
            @endforeach
          </div>
        @empty
          <div class="empty">No situation report at this time</div>
        @endforelse
      </div>
    </section>

    {{-- Warnings --}}
    <section aria-label="Warnings">
      <span class="col-head ch-danger">Warnings @if ($warnings->count())<span class="count">{{ $warnings->count() }}</span>@endif</span>
      <div class="stack">
        @forelse ($warnings as $i)
          <article class="card item is-warning">
            <h4>{{ $i->title }}</h4>
            @if ($i->body)<p>{!! nl2br(e($i->body)) !!}</p>@endif
            @include("public._media")
            <p><span class="badge b-danger">Warning</span></p>
            <span class="when">Occurred {{ $i->published_at->format('D j M, H:i') }}</span>
          </article>
        @empty
          <div class="empty">None at this time</div>
        @endforelse
      </div>
    </section>

    {{-- Monitoring --}}
    <section aria-label="Monitoring and minor issues">
      <span class="col-head ch-warning">Monitoring/Minor Issues @if ($monitoring->count())<span class="count">{{ $monitoring->count() }}</span>@endif</span>
      <div class="stack">
        @forelse ($monitoring as $i)
          <article class="card item is-monitoring">
            <h4>{{ $i->title }}</h4>
            @if ($i->body)<p>{!! nl2br(e($i->body)) !!}</p>@endif
            @include("public._media")
            <p><span class="badge b-warning">Monitoring</span></p>
            <span class="when">Occurred {{ $i->published_at->format('D j M, H:i') }}</span>
          </article>
        @empty
          <div class="empty">None reported at this time</div>
        @endforelse
      </div>
    </section>

    {{-- Resolved --}}
    <section aria-label="Resolved">
      <span class="col-head ch-success">Resolved</span>
      <div class="stack">
        @forelse ($resolved as $i)
          <article class="card item is-resolved">
            <h4>{{ $i->title }}</h4>
            @if ($i->body)<p>{!! nl2br(e($i->body)) !!}</p>@endif
            @include("public._media")
            <p><span class="badge b-success">Resolved</span></p>
            <span class="when">Resolved {{ $i->resolved_at->format('D j M, H:i') }}</span>
          </article>
        @empty
          <div class="empty">None reported at this time</div>
        @endforelse
      </div>
    </section>
  </div>
@endsection
