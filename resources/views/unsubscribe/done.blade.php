@extends('unsubscribe.layout')

@section('title', 'Unsubscribed')

@section('content')
    <h1>You're unsubscribed</h1>
    <p><strong>{{ $email }}</strong> will no longer receive marketing email from {{ $senderName }}.</p>
@endsection
