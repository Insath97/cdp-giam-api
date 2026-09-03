<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetAssistanceMail extends Mailable
{
    use Queueable;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
        public string $loginUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'GIAM Password Reset Assistance Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "
                <div style='font-family: sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #4f46e5;'>Password Reset Assistance Approved</h2>
                    <p>Hello {$this->user->name},</p>
                    <p>Your password reset assistance request has been approved by an authorized administrator.</p>
                    <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 20px 0;'>
                        <p style='margin: 0 0 8px;'><strong>Username:</strong> <code>{$this->user->username}</code></p>
                        <p style='margin: 0 0 8px;'><strong>New Temporary Password:</strong> <code>{$this->temporaryPassword}</code></p>
                        <p style='margin: 0;'><strong>Login Link:</strong> <a href='{$this->loginUrl}' style='color: #4f46e5;'>{$this->loginUrl}</a></p>
                    </div>
                    <p style='color: #dc2626; font-weight: 600;'>You must change your password immediately upon login.</p>
                    <p style='font-size: 12px; color: #64748b; margin-top: 30px;'>If you did not request this assistance, please notify IT Security immediately.</p>
                </div>
            "
        );
    }
}
