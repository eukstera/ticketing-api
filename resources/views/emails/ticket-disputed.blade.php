<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Ticket {{ $number }}</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #222; line-height: 1.5;">
    <p>The requester of job request <strong>{{ $number }}</strong> reported that the work is not resolved.</p>
    <blockquote style="margin: 0 0 16px; padding: 8px 12px; border-left: 3px solid #999; background: #f6f6f6;">{{ $reason }}</blockquote>
    <p>The ticket is back In-progress with the same technician.</p>
</body>
</html>
