@extends('layouts.public', ['title' => 'SAPS Releases', 'subtitle' => 'Latest Releases - North Durban and Surrounds Safety Status Updates'])

@section('content')
  <div class="page-links">
    <a class="btn-back" href="{{ route('home') }}">&larr; Back to Main Page</a>
  </div>

  <div class="notice" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
    <div>
      <p>All information is provided as is from the source linked in the article. Whilst all efforts are made to ensure it is verified, accurate, and up-to-date, we cannot guarantee 100% accuracy and accept no liability for information provided.</p>
    </div>
  </div>

  <span class="col-head ch-primary">Latest News</span>
  <div class="news-list">
    @forelse ($items as $n)
      <article class="card news-item">
        <h3>{{ $n->title }}</h3>
        <div class="news-meta">
          @if ($n->source_url && preg_match('#^https?://#i', $n->source_url))
            <span>Source: <a href="{{ $n->source_url }}" target="_blank" rel="noopener noreferrer">{{ $n->source_name ?: 'Link' }}</a></span>
          @endif
          <span>{{ $n->published_at->format('l j F Y, H:i') }}</span>
        </div>
        @if ($n->body)
          @if (mb_strlen($n->body) > 360)
            <details>
              <summary><span class="more-closed">{{ \Illuminate\Support\Str::limit($n->body, 300) }}<br>Read the full release &darr;</span><span class="more-open">Show less &uarr;</span></summary>
              <p>{!! nl2br(e($n->body)) !!}</p>
            </details>
          @else
            <p>{!! nl2br(e($n->body)) !!}</p>
          @endif
        @endif
      </article>
    @empty
      <div class="empty">No releases yet</div>
    @endforelse
  </div>
@endsection
