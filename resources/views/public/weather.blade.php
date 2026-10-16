@extends('layouts.public', ['title' => 'Weather warning', 'subtitle' => 'Weather Warning - North Durban and Surrounds'])

@section('content')
  <div class="page-links">
    <a class="btn-back" href="{{ route('weather.index') }}">&larr; All weather alerts</a>
  </div>

  @include('public._weather_detail', ['w' => $w, 'level' => $level])
@endsection
