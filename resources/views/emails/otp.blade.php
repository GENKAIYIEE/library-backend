<!DOCTYPE html>
<html>

<head>
    <title>Password Reset OTP</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h2 style="color: #2c3e50;">Password Reset Request</h2>
        <p>Hello,</p>
        <p>You are receiving this email because we received a password reset request for your account.</p>
        <p>Your One-Time Password (OTP) is:</p>
        <div
            style="font-size: 24px; font-weight: bold; background-color: #f4f4f4; padding: 10px; text-align: center; border-radius: 4px; letter-spacing: 5px; margin: 20px 0;">
            {{ $otp }}
        </div>
        <p>This OTP is valid for 10 minutes.</p>
        <p>If you did not request a password reset, no further action is required.</p>
        <br>
        <p>Regards,<br>{{ config('app.name') }}</p>
    </div>
</body>

</html>