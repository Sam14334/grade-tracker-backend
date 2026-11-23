<?php

declare(strict_types=1);

// send email with resend
function sendResendEmail(string $to, string $subject, string $html): bool {
    $api_key = requiredConfig('RESEND_API_KEY');
    $from = requiredConfig('RESEND_FROM_EMAIL');

    $ch = curl_init('https://api.resend.com/emails');
    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }

    $data = json_decode((string)$response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception($data['message'] ?? 'Failed to send email via Resend');
    }

    return true;

}

// html email template for resend otp

function otpEmailHtml(string $fullName, string $otpCode): string {
    $name = htmlspecialchars($fullName);
    return "
    <div style='font-family: sans-serif; max-width: 480px; margin: auto; padding: 24px; border: 1px solid #f1dede; border-radius: 12px;'>
            <h2 style='color: #800020; margin-bottom: 8px;'>GradeSeer Verification</h2>
            <p>Hello <strong>{$name}</strong>,</p>
            <p>Use the 6-digit verification code below to verify your GradeSeer account:</p>
            <div style='text-align: center; margin: 24px 0;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 4px; color: #800020; background: #fdf2f4; padding: 10px 24px; border-radius: 8px; border: 1px dashed #be2b4b;'>{$otpCode}</span>
            </div>
            <p style='color: #666; font-size: 13px;'>This code is valid for 10 minutes. If you did not request this, please ignore this email.</p>
        </div>
    ";
}

?>