<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Job Application Deadline Is Coming</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f6f7f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">
                <tr>
                    <td style="padding:28px 32px 8px 32px;">
                        <h1 style="margin:0;font-size:20px;font-weight:600;color:#111827;">
                            Hello {{ $recipientName }},
                        </h1>
                        <p style="margin:12px 0 0 0;font-size:15px;line-height:1.6;color:#374151;">
                            Your application deadline is approaching.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <p style="margin:0;font-size:13px;color:#6b7280;">Position</p>
                                    <p style="margin:4px 0 0 0;font-size:15px;font-weight:600;color:#111827;">{{ $position }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:14px 16px;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:13px;color:#6b7280;">Company</p>
                                    <p style="margin:4px 0 0 0;font-size:15px;font-weight:600;color:#111827;">{{ $company }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:14px 16px;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:13px;color:#6b7280;">Deadline</p>
                                    <p style="margin:4px 0 0 0;font-size:15px;font-weight:600;color:#dc2626;">{{ $deadline }}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 28px 32px;">
                        <p style="margin:0;font-size:15px;color:#374151;">Don't forget to apply.</p>
                        <p style="margin:20px 0 0 0;font-size:13px;color:#9ca3af;">
                            — JobFlow AI · deadline reminders
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>