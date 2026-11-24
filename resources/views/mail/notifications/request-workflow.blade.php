<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $notificationTitle }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .email-shell { width: 100% !important; }
            .email-padding { padding-left: 24px !important; padding-right: 24px !important; }
            .summary-label,
            .summary-value { display: block !important; width: 100% !important; }
            .summary-label { padding-bottom: 4px !important; }
            .summary-value { padding-top: 0 !important; }
            .action-button { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f7ff; color: #1e293b; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent;">
        {{ $notificationMessage }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #f3f7ff;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="email-shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border: 1px solid #d8e2f1; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(22, 47, 101, 0.1);">
                    <tr>
                        <td style="height: 5px; background-color: #245ab7; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 24px 40px; background-color: #162f65;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="106" valign="middle">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffffff; border-radius: 10px;">
                                            <tr>
                                                <td style="padding: 8px 10px;">
                                                    <img src="{{ $message->embed($logoPath) }}" width="82" alt="SMCT Group of Companies" style="display: block; width: 82px; max-width: 82px; height: auto; border: 0;">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td valign="middle" style="padding-left: 14px;">
                                        <div style="color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 1.4px; line-height: 19px; text-transform: uppercase;">Request<br>Form</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 36px 40px 12px;">
                            <div style="display: inline-block; margin-bottom: 16px; padding: 6px 10px; border-radius: 999px; background-color: #e8f0ff; color: #1e4f9b; font-size: 12px; font-weight: 700; letter-spacing: 0.4px; line-height: 16px; text-transform: uppercase;">{{ $status }}</div>
                            <h1 style="margin: 0 0 14px; color: #162f65; font-size: 26px; font-weight: 700; line-height: 34px;">{{ $notificationTitle }}</h1>
                            <p style="margin: 0; color: #475569; font-size: 16px; line-height: 26px;">{{ $notificationMessage }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 24px 40px 8px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid #d8e2f1; border-radius: 12px; overflow: hidden;">
                                <tr>
                                    <td colspan="2" style="padding: 14px 18px; background-color: #f3f7ff; border-bottom: 1px solid #d8e2f1; color: #162f65; font-size: 13px; font-weight: 700; letter-spacing: 0.5px; line-height: 18px; text-transform: uppercase;">Request details</td>
                                </tr>
                                <tr>
                                    <td class="summary-label" width="36%" style="padding: 16px 18px 8px; color: #64748b; font-size: 13px; font-weight: 700; line-height: 20px;">Request code</td>
                                    <td class="summary-value" style="padding: 16px 18px 8px; color: #0f172a; font-size: 14px; font-weight: 700; line-height: 20px;">{{ $requestCode }}</td>
                                </tr>
                                <tr>
                                    <td class="summary-label" width="36%" style="padding: 8px 18px; color: #64748b; font-size: 13px; font-weight: 700; line-height: 20px;">Form type</td>
                                    <td class="summary-value" style="padding: 8px 18px; color: #0f172a; font-size: 14px; line-height: 20px;">{{ $formType }}</td>
                                </tr>
                                <tr>
                                    <td class="summary-label" width="36%" style="padding: 8px 18px 16px; color: #64748b; font-size: 13px; font-weight: 700; line-height: 20px;">Current status</td>
                                    <td class="summary-value" style="padding: 8px 18px 16px; color: #0f172a; font-size: 14px; font-weight: 700; line-height: 20px;">{{ $status }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 24px 40px 40px;">
                            <a href="{{ $actionUrl }}" class="action-button" style="display: inline-block; padding: 13px 22px; border-radius: 10px; background-color: #245ab7; color: #ffffff; font-size: 15px; font-weight: 700; line-height: 20px; text-decoration: none;">{{ $actionLabel }}</a>
                            <p style="margin: 20px 0 0; color: #94a3b8; font-size: 12px; line-height: 19px;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $actionUrl }}" style="color: #245ab7; text-decoration: underline; word-break: break-all;">{{ $actionUrl }}</a></p>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 22px 40px; background-color: #f3f7ff; border-top: 1px solid #d8e2f1;">
                            <p style="margin: 0; color: #64748b; font-size: 12px; line-height: 19px;">This is an automated workflow notification from <strong>{{ $brandName }}</strong>. Please do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
