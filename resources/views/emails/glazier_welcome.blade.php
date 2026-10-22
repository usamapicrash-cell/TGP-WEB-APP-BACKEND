<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Welcome to The Glass People</title>
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
            <h3 style="margin-top: 0; color: #34497e;">Welcome, {{ $user->name ?? 'Glazier' }}!</h3>
            <p style="font-size: 14px; line-height: 1.6; color: #555;">
                Your account has been successfully created. You can now access your Glazier portal using the login credentials below:
            </p>

            <!-- Credentials Box -->
            <div style="background-color: #f1f3f9; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #34497e;">
                <p style="margin: 0 0 10px 0; font-size: 14px;"><strong>Portal Link:</strong> <a href="{{ $loginUrl }}" style="color: #34497e; word-break: break-all;">{{ $loginUrl }}</a></p>
                <p style="margin: 0 0 10px 0; font-size: 14px;"><strong>Email Address:</strong> {{ $user->email }}</p>
                <p style="margin: 0; font-size: 14px;"><strong>Password:</strong> <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold; color: #2d3748;">{{ $password }}</code></p>
            </div>

            <!-- Login Button -->
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $loginUrl }}" style="background-color: #34497e; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-block;">
                    Login to Portal
                </a>
            </div>

            <p style="font-size: 13px; color: #777; line-height: 1.5;">
                For security reasons, we strongly recommend changing your password after logging in for the first time.
            </p>

            <p style="font-size: 13px; color: #777; margin-top: 30px;">
                Best Regards, <br>
                <strong>The Glass People Team</strong>
            </p>
        </div>
    </div>
</body>
</html>