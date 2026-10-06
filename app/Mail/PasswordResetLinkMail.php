<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public User $user,
        public string $resetUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'GIAM Self-Service Password Reset Request',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "
                <div style='font-family: sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #4f46e5;'>Password Reset Request</h2>
                    <p>Hello {$this->user->name},</p>
                    <p>You requested a self-service password reset for your GIAM Principal account.</p>
                    <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 20px 0;'>
                        <p style='margin: 0 0 12px;'>Click the link below to set a new password. This single-use link expires in 15 minutes.</p>
                        <a href='{$this->resetUrl}' style='display: inline-block; background: #4f46e5; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600;'>Reset Password</a>
                    </div>
                    <p style='font-size: 12px; color: #64748b;'>If you did not request this reset, please ignore this email.</p>
                </div>
            "
        );
    }
}
