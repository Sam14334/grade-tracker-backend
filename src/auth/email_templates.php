<?php

function otpEmailHtml(string $fullName, string $otpCode): string {
    return "
        <div style='font-family: sans-serif; max-width: 480px; margin: auto; padding: 24px; border: 1px solid #f1dede; border-radius: 12px;'>
            <h2 style='color: #800020; margin-bottom: 8px;'>Grade Tracker Verification</h2>
            <p>Hello <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
            <p>Use the 6-digit verification code below to verify your PUP account:</p>
            <div style='text-align: center; margin: 24px 0;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 4px; color: #800020; background: #fdf2f4; padding: 10px 24px; border-radius: 8px; border: 1px dashed #be2b4b;'>{$otpCode}</span>
            </div>
            <p style='color: #666; font-size: 13px;'>This code is valid for 10 minutes. If you did not request this, please ignore this email.</p>
        </div>
    ";
}

function resendOtpEmailHtml(string $fullName, string $otpCode): string {
    return "
        <div style='font-family: sans-serif; max-width: 480px; margin: auto; padding: 24px; border: 1px solid #f1dede; border-radius: 12px;'>
            <h2 style='color: #800020; margin-bottom: 8px;'>Grade Tracker Verification</h2>
            <p>Hello <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
            <p>Here is your new 6-digit verification code:</p>
            <div style='text-align: center; margin: 24px 0;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 4px; color: #800020; background: #fdf2f4; padding: 10px 24px; border-radius: 8px; border: 1px dashed #be2b4b;'>{$otpCode}</span>
            </div>
            <p style='color: #666; font-size: 13px;'>This code is valid for 10 minutes.</p>
        </div>
    ";
}
