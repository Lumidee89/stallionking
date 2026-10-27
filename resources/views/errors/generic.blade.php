@extends('layouts.app')
@section('title', 'Something went wrong')
@section('content')
<div class="card" style="max-width:520px;margin:48px auto;text-align:center">
  <p class="eyebrow">Error {{ $status }}</p>
  <h1>{{ $message }}</h1>
  @if(!empty($ref))<p class="hint">Reference: <span class="tn">{{ $ref }}</span></p>@endif
  <a class="btn" href="{{ url()->previous() ?: route('track') }}">Go back</a>
</div>
@endsection
