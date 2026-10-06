<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your GIAM Account Is Ready</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 24px 32px; text-align: left;">
                            <div style="font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: 0.02em;">
                                CDP Global Identity &amp; Access Management
                            </div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                                Central Identity Portal (GIAM)
                            </div>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 32px;">
                            <h1 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0;">
                                Your Account Is Ready
                            </h1>
                            <p style="font-size: 14px; line-height: 1.6; color: #334155; margin: 0 0 20px 0;">
                                Hello {{ $user->name }},
                            </p>
                            <p style="font-size: 14px; line-height: 1.6; color: #334155; margin: 0 0 20px 0;">
                                An authorized administrator has provisioned your centralized GIAM Principal account. You may now access the central identity portal using the initial login credentials provided below.
                            </p>

                            <!-- Credentials Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px;">
                                            Account Information
                                        </div>
                                        <div style="margin-bottom: 10px; font-size: 14px;">
                                            <span style="color: #64748b; width: 140px; display: inline-block;">Username:</span>
                                            <strong style="color: #0f172a; font-family: monospace;">{{ $user->username }}</strong>
                                        </div>
                                        <div style="margin-bottom: 10px; font-size: 14px;">
                                            <span style="color: #64748b; width: 140px; display: inline-block;">Temporary Password:</span>
                                            <strong style="color: #0f172a; font-family: monospace; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{{ $temporaryPassword }}</strong>
                                        </div>
                                        <div style="font-size: 14px;">
                                            <span style="color: #64748b; width: 140px; display: inline-block;">Portal Access:</span>
                                            <span style="color: #0f172a;">Active</span>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA Button -->
                            <div style="text-align: center; margin: 28px 0;">
                                <a href="{{ $loginUrl }}" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 12px 28px; border-radius: 6px;">
                                    Sign In to GIAM
                                </a>
                                <div style="font-size: 12px; color: #64748b; margin-top: 10px;">
                                    Direct link: <a href="{{ $loginUrl }}" style="color: #2563eb; text-decoration: underline;">{{ $loginUrl }}</a>
                                </div>
                            </div>

                            <!-- Security Advisory -->
                            <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 14px 16px; margin: 24px 0; border-radius: 0 4px 4px 0;">
                                <div style="font-size: 13px; font-weight: 700; color: #b45309; margin-bottom: 4px;">
                                    Mandatory Security Notice
                                </div>
                                <div style="font-size: 13px; color: #92400e; line-height: 1.5;">
                                    You will be required to change this temporary password immediately upon your first sign-in. Never share your password with anyone. GIAM administrators and support staff will never request your password.
                                </div>
                            </div>

                            <p style="font-size: 12px; color: #64748b; line-height: 1.5; margin: 20px 0 0 0;">
                                If you did not anticipate receiving this account or believe it was issued in error, please notify your organization's IT security administrator immediately.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px; text-align: center;">
                            <div style="font-size: 11px; color: #94a3b8; line-height: 1.5;">
                                This is an automated administrative delivery from the CDP Global Identity &amp; Access Management service.<br>
                                Please do not reply directly to this email.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
