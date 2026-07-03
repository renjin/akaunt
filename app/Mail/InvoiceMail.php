<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invoice->company->name} — Invoice {$this->invoice->invoice_number}",
            replyTo: array_filter([$this->invoice->company->email]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => InvoicePdf::render($this->invoice)->output(),
                InvoicePdf::filename($this->invoice),
            )->withMime('application/pdf'),
        ];
    }
}
