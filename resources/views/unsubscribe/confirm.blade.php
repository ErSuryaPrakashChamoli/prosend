@extends('unsubscribe.layout')

@section('title', 'Unsubscribe')

@section('content')
    <h1>Unsubscribe from {{ $senderName }}?</h1>
    <p><strong>{{ $email }}</strong> will stop receiving newsletters and other marketing email from {{ $senderName }}. Important account and service messages may still be sent.</p>
    <form method="POST" action="{{ $actionUrl }}">
        <button type="submit">Unsubscribe</button>
    </form>
@endsection
