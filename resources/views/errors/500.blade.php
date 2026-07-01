@extends('errors.layout')

@section('title', 'Server Error')

@section('content')
    <div class="owwa-error-code">500</div>
    <h1 class="owwa-error-title">Something went wrong</h1>
    <p class="owwa-error-message">{{ \App\Support\FriendlyMessages::websiteError500() }}</p>
    <div class="owwa-error-actions">
        <a href="{{ url('/admin/login') }}" class="owwa-error-btn owwa-error-btn-primary">Go to login</a>
        <a href="javascript:history.back()" class="owwa-error-btn owwa-error-btn-secondary">Go back</a>
    </div>
@endsection
