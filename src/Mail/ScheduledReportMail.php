<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class ScheduledReportMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public LeadReport $report,
        public ?string $pdfContents = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('lead-pipeline::reports.mail.subject', ['name' => $this->report->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'lead-pipeline::mail.scheduled-report', with: [
            'report' => $this->report,
            'url'    => route('lead-pipeline.reports.show', $this->report->share_token),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (null === $this->pdfContents) {
            return [];
        }

        return [Attachment::fromData(fn (): string => $this->pdfContents, Str::slug($this->report->name) . '.pdf')->withMime('application/pdf')];
    }
}
