@extends('errors.layout')

@section('title', 'Service Unavailable')

@section('content')
    <div class="owwa-error-code">503</div>
    <h1 class="owwa-error-title">Service temporarily unavailable</h1>
    <p class="owwa-error-message">{{ \App\Support\FriendlyMessages::websiteUnavailable503() }}</p>
    <p class="owwa-error-hint">{{ \App\Support\FriendlyMessages::serviceUnavailableHint() }}</p>
    <div class="owwa-error-actions">
        <a href="{{ url('/admin/login') }}" class="owwa-error-btn owwa-error-btn-primary">Go to login</a>
        <a href="javascript:location.reload()" class="owwa-error-btn owwa-error-btn-secondary">Try again</a>
    </div>
@endsection
