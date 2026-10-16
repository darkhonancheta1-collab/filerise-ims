<?php
// mailer.php
// Sends emails using PHPMailer over Gmail SMTP.
// Requires SMTP_HOST / SMTP_PORT / SMTP_USERNAME / SMTP_PASSWORD / SMTP_FROM_NAME
// to be defined in config.php (see config.example.php for the template).

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send the 6-digit password reset code to the user's Gmail.
 *
 * @param string $toEmail   Recipient email address
 * @param string $toName    Recipient display name
 * @param string $code      The 6-digit reset code
 * @return array{ok:bool, error?:string}
 */
function sendResetCodeEmail(string $toEmail, string $toName, string $code): array
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $mail->Password   = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

        // Sender / recipient
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Fil-Rise IMS';
        $mail->setFrom($mail->Username, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($mail->Username, $fromName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Fil-Rise IMS Password Reset Code';
        $safeName = htmlspecialchars($toName ?: 'there', ENT_QUOTES, 'UTF-8');
        $mail->Body = "
            <div style='font-family:Poppins,Arial,sans-serif;max-width:480px;margin:0 auto;'>
              <h2 style='color:#0f172a;'>Password Reset Request</h2>
              <p>Hi {$safeName},</p>
              <p>Use the code below to reset your Fil-Rise IMS password. This code expires in <strong>10 minutes</strong>.</p>
              <div style='font-size:32px;font-weight:800;letter-spacing:8px;background:#f1f5f9;
                          color:#0f172a;padding:16px 20px;border-radius:12px;text-align:center;margin:20px 0;'>
                {$code}
              </div>
              <p style='color:#64748b;font-size:13px;'>If you didn't request this, you can safely ignore this email.</p>
              <p style='color:#94a3b8;font-size:12px;margin-top:24px;'>© 2025 Fil-Rise Inventory Management System</p>
            </div>
        ";
        $mail->AltBody = "Your Fil-Rise IMS password reset code is: {$code}\nThis code expires in 10 minutes.\nIf you didn't request this, ignore this email.";

        $mail->send();
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo];
    }
}