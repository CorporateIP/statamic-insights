<?php

namespace CorporateIp\Insights\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InsightsReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $period, // weekly|monthly
        public readonly string $label,  // human-readable period, e.g. "Jun 1 - Jun 30"
        public readonly array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->period === 'monthly'
            ? __('Monthly Insights report')
            : __('Weekly Insights report');

        return new Envelope(subject: "{$subject} · ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'statamic-insights::mail.report');
    }
}
