@extends('errors.layout')

@section('title', 'Page Not Found')

@section('content')
    <div class="owwa-error-code">404</div>
    <h1 class="owwa-error-title">Page not found</h1>
    <p class="owwa-error-message">{{ \App\Support\FriendlyMessages::pageNotFound404() }}</p>
    <div class="owwa-error-actions">
        <a href="{{ url('/admin/login') }}" class="owwa-error-btn owwa-error-btn-primary">Go to login</a>
    </div>
@endsection
