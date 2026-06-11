<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Policies;

use Illuminate\Foundation\Auth\User as Authenticatable;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class LeadReportPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->can($this->permission('view'));
    }

    public function view(Authenticatable $user, LeadReport $report): bool
    {
        return $user->can($this->permission('view')) && $this->sameTeam($user, $report);
    }

    public function create(Authenticatable $user): bool
    {
        return $user->can($this->permission('create'));
    }

    public function update(Authenticatable $user, LeadReport $report): bool
    {
        return $user->can($this->permission('update')) && $this->sameTeam($user, $report);
    }

    public function delete(Authenticatable $user, LeadReport $report): bool
    {
        return $user->can($this->permission('delete')) && $this->sameTeam($user, $report);
    }

    public function share(Authenticatable $user, LeadReport $report): bool
    {
        return $user->can($this->permission('share')) && $this->sameTeam($user, $report);
    }

    private function permission(string $action): string
    {
        return (string) config("lead-pipeline.reports.permissions.{$action}", "{$action}_reports");
    }

    private function sameTeam(Authenticatable $user, LeadReport $report): bool
    {
        $teamFk         = config('lead-pipeline.tenancy.foreign_key', 'team_uuid');
        $currentTeamKey = \Filament\Facades\Filament::getTenant()?->getKey()
            ?? $user->{$teamFk}
            ?? null;

        return null !== $currentTeamKey && (string) $report->{$teamFk} === (string) $currentTeamKey;
    }
}
