<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Policies;

use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User as Authenticatable;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class LeadReportPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'view');
    }

    public function view(Authenticatable $user, LeadReport $report): bool
    {
        return $this->hasPermission($user, 'view') && $this->sameTeam($user, $report);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'create');
    }

    public function update(Authenticatable $user, LeadReport $report): bool
    {
        return $this->hasPermission($user, 'update') && $this->sameTeam($user, $report);
    }

    public function delete(Authenticatable $user, LeadReport $report): bool
    {
        return $this->hasPermission($user, 'delete') && $this->sameTeam($user, $report);
    }

    public function share(Authenticatable $user, LeadReport $report): bool
    {
        return $this->hasPermission($user, 'share') && $this->sameTeam($user, $report);
    }

    private function hasPermission(Authenticatable $user, string $action): bool
    {
        if ( ! $this->permissionsEnforced()) {
            return true;
        }

        return $user->can((string) config("lead-pipeline.reports.permissions.{$action}", "{$action}_reports"));
    }

    /**
     * Spatie-Permissions werden nur in den konfigurierten Panels erzwungen
     * (permission_panels: null = überall). In allen anderen Panels gilt
     * ausschließlich die Team-Isolation — z. B. Admin-Panels ohne Rollen-Setup.
     */
    private function permissionsEnforced(): bool
    {
        $panels = config('lead-pipeline.reports.permission_panels');

        if (null === $panels) {
            return true;
        }

        $current = Filament::getCurrentPanel()?->getId();

        return null === $current || in_array($current, (array) $panels, true);
    }

    private function sameTeam(Authenticatable $user, LeadReport $report): bool
    {
        $teamFk         = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $currentTeamKey = Filament::getTenant()?->getKey()
            ?? $user->{$teamFk}
            ?? null;

        return null !== $currentTeamKey && (string) $report->{$teamFk} === (string) $currentTeamKey;
    }
}
