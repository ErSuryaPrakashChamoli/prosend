{!! $rendered->text !!}
@if ($rendered->unsubscribeUrl)

--
You are receiving this email from {!! $senderName !!} because you subscribed.
Unsubscribe: {!! $rendered->unsubscribeUrl !!}
@if (filled($postalAddress ?? null))
{!! $postalAddress !!}
@endif
@endif
