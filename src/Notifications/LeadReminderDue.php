<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use JohnWink\FilamentLeadPipeline\Models\Lead;

/*
 * Bewusst ohne Queueable-Trait — siehe FacebookConnectionAlert: dessen
 * $connection-Property kollidiert mit promoteten Properties; das Pattern
 * bleibt hier konsistent, gesendet wird synchron aus dem Scheduler-Command.
 */
class LeadReminderDue extends Notification
{
    public function __construct(
        public Lead $lead,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** Filament-kompatibles Format, damit der Reminder im Panel-Glockensymbol erscheint. */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->warning()
            ->icon('heroicon-o-bell-alert')
            ->title(__('lead-pipeline::lead-pipeline.reminder.notification_title', ['name' => $this->lead->name]))
            ->body(
                $this->lead->reminder_at?->format('d.m.Y H:i')
                . ($this->lead->reminder_note ? ' — ' . $this->lead->reminder_note : ''),
            )
            ->getDatabaseMessage();
    }
}
