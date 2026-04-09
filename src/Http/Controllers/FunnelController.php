<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;

class FunnelController extends Controller
{
    public function show(string $slug): View
    {
        $funnel = LeadFunnel::where('slug', $slug)
            ->where('is_active', true)
            ->with(['steps.fields.definition', 'board', 'source'])
            ->firstOrFail();

        $funnel->incrementViews();

        return view('lead-pipeline::funnel.layout', compact('funnel'));
    }
}
