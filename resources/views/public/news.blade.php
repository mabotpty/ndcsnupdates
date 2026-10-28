@extends('layouts.public', ['title' => 'Latest News', 'subtitle' => 'Latest News - North Durban and Surrounds Safety Status Updates'])

@section('content')
  <div class="page-links">
    <a class="btn-back" href="{{ route('home') }}">&larr; Back to Main Page</a>
  </div>

  <div class="notice" role="note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>
    <div>
      <p>All information is provided as is from the source linked in the article. Whilst all efforts are made to ensure it is verified, accurate, and up-to-date, we cannot guarantee 100% accuracy and accept no liability for information provided.</p>
      <p><strong>Please note:</strong> only important news/updates regarding safety and security for the last 14 days are posted here.</p>
    </div>
  </div>

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
              <summary><span class="more-closed"><span class="excerpt">{{ \Illuminate\Support\Str::limit($n->body, 300) }}</span><span class="btn-more">Read the full release &darr;</span></span><span class="more-open"><span class="btn-more">Show less &uarr;</span></span></summary>
              <p>{!! nl2br(e($n->body)) !!}</p>
            </details>
          @else
            <p>{!! nl2br(e($n->body)) !!}</p>
          @endif
        @endif
      </article>
    @empty
      <div class="empty news-empty">
        No relevant information available at this time. If you feel there is something that should be here,
        @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer">send us a WhatsApp</a>@else send us a WhatsApp @endif.
      </div>
    @endforelse
  </div>
@endsection
