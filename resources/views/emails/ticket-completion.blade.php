<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Ticket {{ $number }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; line-height: 1.5;">
    <p>Good day,</p>
    <p>Your IT job request <strong>{{ $number }}</strong> has been marked as completed. Here is what the technician reported:</p>
    <blockquote style="margin: 0 0 16px; padding: 8px 12px; border-left: 3px solid #999; background: #f6f6f6;">{{ $notes }}</blockquote>
    <p>Please tell us whether the work was done to your satisfaction:</p>
    <p><a href="{{ $url }}" style="display: inline-block; padding: 10px 18px; background: #1d4ed8; color: #fff; text-decoration: none; border-radius: 4px;">Confirm or report a problem</a></p>
    <p>If the button does not open, copy this address into your browser (the hospital network is required):<br>{{ $url }}</p>
    <p>If we do not hear from you within {{ $days }} days, the request will be closed automatically.</p>
    <p>Thank you.<br>ICTS</p>
</body>
</html>
