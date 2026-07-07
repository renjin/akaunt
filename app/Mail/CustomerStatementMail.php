<?php

namespace App\Mail;

use App\Models\Party;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array  $statement  Payload from CustomerStatement::getStatement().
     */
    public function __construct(
        public Party $party,
        public array $statement,
        public ?string $from = null,
        public ?string $to = null,
    ) {
        $this->locale($party->company->document_locale ?? 'en');
    }

    public function envelope(): Envelope
    {
        $company = $this->party->company;

        return new Envelope(
            subject: "{$company->name} — Statement of account",
            replyTo: array_filter([$company->email]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.customer-statement');
    }
}
