<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

function generate_otp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function send_otp_email(string $email, string $name, string $otp): bool
{
    global $config;

    if (!class_exists(PHPMailer::class)) {
        error_log('[CPDO Mailer] PHPMailer not installed. Run: composer require phpmailer/phpmailer');
        audit_log(null, 'OTP_EMAIL_FAILED', 'users', null, [
            'email'  => $email,
            'reason' => 'PHPMailer not installed',
        ]);
        return false;
    }

    $mailConfig = $config['mail'] ?? [];
    $host       = trim((string)($mailConfig['host'] ?? ''));
    $username   = trim((string)($mailConfig['username'] ?? ''));
    $password   = (string)($mailConfig['password'] ?? '');
    $port       = (int)($mailConfig['port'] ?? 587);
    $encryption = trim((string)($mailConfig['encryption'] ?? 'tls'));
    $fromEmail  = trim((string)($mailConfig['from_email'] ?? 'no-reply@localhost.test'));
    $fromName   = trim((string)($mailConfig['from_name'] ?? 'CPDO Land Portal'));

    $mail = new PHPMailer(true);

    try {
        if ($host !== '') {
            /* ── SMTP mode ── */
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth   = ($username !== '');
            if ($username !== '') {
                $mail->Username = $username;
                $mail->Password = $password;
            }
            /* Uncomment the next line temporarily to debug SMTP connection issues */
            // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        } else {
            /* ── PHP mail() fallback (local dev only) ── */
            $mail->isMail();
        }

        $mail->CharSet  = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Your CPDO Portal verification code';

        $safeOtp  = e($otp);
        $safeName = e($name);
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Inter,Arial,sans-serif;background:#f4f7fb;margin:0;padding:32px 0;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.07);overflow:hidden;">
    <div style="background:#0b2a4a;padding:24px 32px;">
      <span style="color:#fff;font-size:1.2rem;font-weight:900;">CPDO Land Portal</span>
    </div>
    <div style="padding:32px;">
      <p style="margin:0 0 12px;color:#121212;">Hello {$safeName},</p>
      <p style="margin:0 0 24px;color:#62748a;">Use the code below to verify your email address. It expires in <strong>5 minutes</strong>.</p>
      <div style="text-align:center;margin:0 0 24px;">
        <span style="display:inline-block;font-size:2.4rem;font-weight:900;letter-spacing:.35em;color:#0b2a4a;background:#eaf4ff;border-radius:10px;padding:16px 28px;">{$safeOtp}</span>
      </div>
      <p style="margin:0;color:#62748a;font-size:.88rem;">If you did not request this code, you can safely ignore this email.</p>
    </div>
  </div>
</body>
</html>
HTML;
        $mail->AltBody = "Hello {$name},\n\nYour CPDO Portal verification code is: {$otp}\n\nThis code expires in 5 minutes.\n\nIf you did not request this, ignore this email.";

        $mail->send();
        return true;

    } catch (MailException $exception) {
        $reason = $exception->getMessage();
        error_log('[CPDO Mailer] OTP email failed to ' . $email . ': ' . $reason);
        audit_log(null, 'OTP_EMAIL_FAILED', 'users', null, [
            'email'  => $email,
            'reason' => $reason,
        ]);
        return false;
    }
}

function issue_user_otp(int $userId, string $email, string $name): bool
{
    $otp  = generate_otp();
    $stmt = db()->prepare(
        'UPDATE users SET otp_code = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 5 MINUTE), is_verified = 0 WHERE id = ?'
    );
    $stmt->execute([$otp, $userId]);

    $sent = send_otp_email($email, $name, $otp);

    if ($sent) {
        audit_log($userId, 'OTP_ISSUED', 'users', $userId);
    } else {
        audit_log($userId, 'OTP_ISSUE_FAILED', 'users', $userId, ['email' => $email]);
    }

    return $sent;
}
