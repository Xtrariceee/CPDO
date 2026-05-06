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
        $mail->Subject = 'Your CPDO Portal verification code';
        $mail->isHTML(true);

        $safeOtp  = htmlspecialchars($otp,    ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Inter,Arial,sans-serif;background:#f4f7fb;margin:0;padding:32px 0;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;
              box-shadow:0 4px 16px rgba(0,0,0,.07);overflow:hidden;">
    <div style="background:#0b2a4a;padding:24px 32px;">
      <span style="color:#fff;font-size:1.2rem;font-weight:900;">CPDO Land Portal</span>
    </div>
    <div style="padding:32px;">
      <p style="margin:0 0 12px;color:#121212;">Hello {$safeName},</p>
      <p style="margin:0 0 24px;color:#62748a;">
        Use the code below to verify your email address.
        It expires in <strong>5 minutes</strong>.
      </p>
      <div style="text-align:center;margin:0 0 24px;">
        <span style="display:inline-block;font-size:2.4rem;font-weight:900;
                     letter-spacing:.35em;color:#0b2a4a;background:#eaf4ff;
                     border-radius:10px;padding:16px 28px;">{$safeOtp}</span>
      </div>
      <p style="margin:0;color:#62748a;font-size:.88rem;">
        If you did not request this code, you can safely ignore this email.
      </p>
    </div>
  </div>
</body>
</html>
HTML;
        $mail->AltBody =
            "Hello {$toName},\n\n"
            . "Your CPDO Portal verification code is: {$otp}\n\n"
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
