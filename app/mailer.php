<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/* ═══════════════════════════════════════════════════════════════
   OTP helpers
   ═══════════════════════════════════════════════════════════════ */

function generate_otp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/* ═══════════════════════════════════════════════════════════════
   Core mailer — builds and returns a configured PHPMailer instance.
   Throws RuntimeException with a human-readable message on misconfiguration.
   ═══════════════════════════════════════════════════════════════ */

function build_mailer(): PHPMailer
{
    global $config;

    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException(
            'PHPMailer is not installed. Run: composer require phpmailer/phpmailer'
        );
    }

    $mc         = $config['mail'] ?? [];
    $host       = trim((string)($mc['host']       ?? ''));
    $username   = trim((string)($mc['username']   ?? ''));
    $password   = (string)($mc['password']        ?? '');
    $port       = (int)($mc['port']               ?? 587);
    $encryption = strtolower(trim((string)($mc['encryption'] ?? 'tls')));
    $fromEmail  = trim((string)($mc['from_email'] ?? ''));
    $fromName   = trim((string)($mc['from_name']  ?? 'CPDO Land Portal'));

    /* ── Config guards ── */
    if ($host !== '' && $username === '') {
        throw new RuntimeException(
            'Mail host is set but username is empty. '
            . 'Add your Gmail address and App Password to config/env.php → mail section.'
        );
    }

    // Detect placeholder / unconfigured credentials
    $looksLikePlaceholder = static function (string $value, string $field): bool {
        $lower = strtolower($value);
        return $value === ''
            || str_contains($lower, 'your-')
            || str_contains($lower, 'your_')
            || str_contains($lower, 'replace')
            || str_contains($lower, 'placeholder')
            || str_contains($lower, 'xxxx')
            || ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL));
    };

    if ($host !== '' && $looksLikePlaceholder($username, 'email')) {
        throw new RuntimeException(
            "Mail username '{$username}' looks like a placeholder. "
            . 'Set your real Gmail address in config/env.php → mail.username.'
        );
    }
    if ($host !== '' && $looksLikePlaceholder($password, 'password')) {
        throw new RuntimeException(
            'Mail password looks like a placeholder or is empty. '
            . 'Generate a Gmail App Password at myaccount.google.com/apppasswords '
            . 'and set it in config/env.php → mail.password.'
        );
    }

    // Auto-fill from_email from username when it's still the localhost default
    if ($fromEmail === '' || $fromEmail === 'no-reply@localhost.test'
        || $looksLikePlaceholder($fromEmail, 'email')) {
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $username;
        }
    }

    $mail = new PHPMailer(true); // true = throw exceptions

    if ($host !== '') {
        /* ── SMTP mode ── */
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;

        // Gmail requires STARTTLS on 587, SMTPS on 465
        if ($encryption === 'ssl' || $port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // Keep-alive for multiple sends in one request
        $mail->SMTPKeepAlive = true;

        // Uncomment to debug SMTP handshake:
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        // $mail->Debugoutput = 'error_log';
    } else {
        /* ── PHP mail() fallback (requires local MTA) ── */
        $mail->isMail();
    }

    $mail->CharSet  = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;
    $mail->setFrom($fromEmail ?: 'no-reply@example.com', $fromName);

    return $mail;
}

/* ═══════════════════════════════════════════════════════════════
   send_otp_email
   ═══════════════════════════════════════════════════════════════ */

function send_otp_email(string $toEmail, string $toName, string $otp): bool
{
    try {
        $mail = build_mailer();

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = 'Your RentEase verification code';
        $mail->isHTML(true);

        $safeOtp  = htmlspecialchars($otp,    ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5f0e8;font-family:Inter,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f0e8;padding:40px 16px;">
    <tr>
      <td align="center">
        <table width="100%" style="max-width:480px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 32px rgba(36,27,11,0.10);">

          <!-- Header -->
          <tr>
            <td style="background:#241b0b;padding:28px 36px;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <span style="display:inline-flex;align-items:center;gap:10px;">
                      <span style="display:inline-block;width:36px;height:36px;border-radius:50%;background:#f6cf4a;text-align:center;line-height:36px;font-size:14px;font-weight:900;color:#241b0b;">RE</span>
                      <span style="color:#f6cf4a;font-size:1.15rem;font-weight:900;letter-spacing:-.01em;">RentEase</span>
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:36px 36px 28px;">
              <p style="margin:0 0 8px;font-size:1.05rem;font-weight:800;color:#241b0b;">Hello, {$safeName}</p>
              <p style="margin:0 0 28px;color:#76684b;font-size:.92rem;line-height:1.65;">
                Use the verification code below to confirm your email address.
                This code expires in <strong style="color:#241b0b;">5 minutes</strong>.
              </p>

              <!-- OTP box -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td align="center">
                    <div style="display:inline-block;background:#fff7d6;border:2px solid #f0dfad;border-radius:14px;padding:20px 36px;">
                      <span style="font-size:2.6rem;font-weight:900;letter-spacing:.35em;color:#241b0b;font-variant-numeric:tabular-nums;">{$safeOtp}</span>
                    </div>
                  </td>
                </tr>
              </table>

              <p style="margin:0;color:#a89562;font-size:.82rem;line-height:1.6;">
                If you did not request this code, you can safely ignore this email.
                Someone may have entered your email address by mistake.
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#fffdf5;border-top:1px solid #f0dfad;padding:18px 36px;">
              <p style="margin:0;font-size:.75rem;color:#a89562;line-height:1.5;">
                © <?= date('Y') ?> RentEase &mdash; Rental Management System<br>
                This is an automated message. Please do not reply.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
        $mail->AltBody =
            "Hello {$toName},\n\n"
            . "Your RentEase verification code is: {$otp}\n\n"
            . "This code expires in 5 minutes.\n\n"
            . "If you did not request this, ignore this email.";

        $mail->send();
        return true;

    } catch (MailException | RuntimeException $e) {
        $reason = $e->getMessage();
        error_log('[CPDO Mailer] OTP email failed to ' . $toEmail . ': ' . $reason);
        audit_log(null, 'OTP_EMAIL_FAILED', 'users', null, [
            'email'  => $toEmail,
            'reason' => $reason,
        ]);
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════
   issue_user_otp  — writes OTP to DB then sends the email
   ═══════════════════════════════════════════════════════════════ */

function issue_user_otp(int $userId, string $email, string $name): bool
{
    $otp = generate_otp();

    db()->prepare(
        'UPDATE users
         SET otp_code = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 5 MINUTE), is_verified = 0
         WHERE id = ?'
    )->execute([$otp, $userId]);

    $sent = send_otp_email($email, $name, $otp);

    audit_log($userId, $sent ? 'OTP_ISSUED' : 'OTP_ISSUE_FAILED', 'users', $userId,
        $sent ? [] : ['email' => $email]
    );

    return $sent;
}

/* ═══════════════════════════════════════════════════════════════
   mail_test  — quick smoke-test, call from a one-off script
   Usage:  php -r "require 'app/bootstrap.php'; var_dump(mail_test('you@example.com'));"
   ═══════════════════════════════════════════════════════════════ */

function mail_test(string $toEmail): array
{
    try {
        $mail = build_mailer();
        $mail->addAddress($toEmail);
        $mail->Subject = 'CPDO Mailer test';
        $mail->Body    = 'If you see this, Gmail SMTP is working correctly.';
        $mail->AltBody = $mail->Body;
        $mail->send();
        return ['ok' => true, 'message' => 'Test email sent to ' . $toEmail];
    } catch (MailException | RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
