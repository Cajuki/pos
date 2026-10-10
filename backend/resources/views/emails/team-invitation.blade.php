<!DOCTYPE html>
<html lang="en">
<body>
    <h1>Join {{ $businessName }} on Poss</h1>
    <p>Hello {{ $inviteeName }},</p>
    <p>You have been invited to join {{ $businessName }} as a {{ $role }}. Accept the invitation to create your account and join the team.</p>
    <p><a href="{{ $invitationUrl }}">Accept invitation</a></p>
    <p>This invitation expires on {{ $expiresAt }}. If you were not expecting this email, you can ignore it.</p>
</body>
</html>
