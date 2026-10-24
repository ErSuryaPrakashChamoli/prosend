<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $rendered->subject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 8px;">
                    <tr>
                        <td style="padding: 32px; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.6; color: #18181b;">
                            {{-- Sanitized by EmailContentRenderer before it reaches the view. --}}
                            {!! $rendered->html !!}
                        </td>
                    </tr>
                </table>
                @if ($rendered->unsubscribeUrl)
                    <p style="max-width: 600px; margin: 16px auto 0; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.5; color: #71717a;">
                        You are receiving this email from {{ $senderName }} because you subscribed.
                        <a href="{{ $rendered->unsubscribeUrl }}" style="color: #71717a;">Unsubscribe</a>
                    </p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
