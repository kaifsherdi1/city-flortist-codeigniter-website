<?php
/**
 * Password-reset email for the dashboard (App\Libraries\PasswordReset).
 *
 * @var string $url
 * @var int    $minutes
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password Notification</title>
</head>
<body style="margin:0;padding:24px;background:#f6f1ec;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#2b2420;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:32px;">
                <h1 style="margin:0 0 16px;font-size:20px;">Hello!</h1>
                <p style="margin:0 0 16px;line-height:1.5;">You are receiving this email because we received a password reset request for your account.</p>
                <p style="margin:24px 0;text-align:center;">
                    <a href="<?= esc($url, 'attr') ?>" style="display:inline-block;padding:12px 24px;background:#7a1f3d;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">Reset Password</a>
                </p>
                <p style="margin:0 0 16px;line-height:1.5;">This password reset link will expire in <?= (int) $minutes ?> minutes.</p>
                <p style="margin:0 0 16px;line-height:1.5;">If you did not request a password reset, no further action is required.</p>
                <p style="margin:0;line-height:1.5;">Regards,<br>City Florist</p>
                <hr style="margin:24px 0;border:0;border-top:1px solid #eee;">
                <p style="margin:0;font-size:12px;line-height:1.5;color:#777;word-break:break-all;">If the button doesn't work, copy this link into your browser: <?= esc($url) ?></p>
            </td>
        </tr>
    </table>
</body>
</html>
