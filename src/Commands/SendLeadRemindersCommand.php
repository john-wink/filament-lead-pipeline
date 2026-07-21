<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Notifications\LeadReminderDue;

class SendLeadRemindersCommand extends Command
{
    protected $signature = 'lead-pipeline:send-lead-reminders';

    protected $description = 'Notify assigned users about due lead reminders (Wiedervorlage).';

    public function handle(): int
    {
        $sent              = 0;
        $notificationClass = (string) config('lead-pipeline.reminders.notification', LeadReminderDue::class);

        Lead::query()
            ->where('status', LeadStatusEnum::Active)
            ->whereNotNull('assigned_to')
            ->whereNotNull('reminder_at')
            ->where('reminder_at', '<=', now())
            ->whereNull('reminder_notified_at')
            ->with('assignedUser')
            ->orderBy('reminder_at')
            ->each(function (Lead $lead) use (&$sent, $notificationClass): void {
                $user = $lead->assignedUser;

                if (null === $user) {
                    return;
                }

                $user->notify(new $notificationClass($lead));
                $lead->forceFill(['reminder_notified_at' => now()])->save();
                $sent++;
            });

        $this->info(sprintf('%d reminder notification(s) sent.', $sent));

        return self::SUCCESS;
    }
}
