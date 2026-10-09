@if (! empty($i->media))
  <p class="media-note">📷
    @if ($telegramUrl)
      <a href="{{ $telegramUrl }}" target="_blank" rel="noopener noreferrer">Imagery available in the Telegram group</a>
    @else
      Imagery available in the Telegram group
    @endif
  </p>
@endif
