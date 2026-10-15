<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reset Password</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f8f9fa; margin: 0; padding: 20px;">
    <div style="max-width: 600px; background: #ffffff; margin: 0 auto; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
        
        <!-- Header -->
        <div style="background-color: #34497e; padding: 25px; text-align: center; color: white;">
            <h2 style="margin: 0; font-size: 22px; letter-spacing: 0.5px;">THE GLASS PEOPLE</h2>
            <p style="margin: 5px 0 0 0; font-size: 12px; opacity: 0.8;">ESTABLISHED & TRUSTED GLAZING SOLUTIONS</p>
        </div>

        <!-- Body Content -->
        <div style="padding: 30px; color: #333333;">
            <h3 style="margin-top: 0; color: #34497e;">Hello {{ $user->name ?? 'User' }},</h3>
            <p style="font-size: 14px; line-height: 1.6; color: #555;">
                You are receiving this email because we received a password reset request for your account. Click the button below to reset your password:
            </p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $resetUrl }}" style="background-color: #34497e; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;">
                    Reset Password
                </a>
            </div>

            <p style="font-size: 13px; color: #777; line-height: 1.5;">
                This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required.
            </p>

            <p style="font-size: 13px; color: #777; margin-top: 30px;">
                Best Regards, <br>
                <strong>The Glass People Team</strong>
            </p>
        </div>
    </div>
</body>
</html>