<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewAccountCredentialsMail extends Mailable
{
    use Queueable;

    public ?string $loginUrl;

    /**
     * Do NOT queue this mailable so plaintext temporary passwords are NEVER stored in a database queue/jobs table.
     */
    public function __construct(
        public User $user,
        public string $temporaryPassword,
        ?string $loginUrl = null
    ) {
        $this->loginUrl = $loginUrl ?: (rtrim(config('app.frontend_url') ?? env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/login');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your GIAM Account Is Ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-account-credentials',
            text: 'emails.new-account-credentials-text',
        );
    }
}
