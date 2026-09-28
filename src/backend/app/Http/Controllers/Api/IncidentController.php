<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Observation;
use App\Services\Reporting\IncidentStats;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(protected IncidentStats $stats) {}

    public function index()
    {
        $incidents = Incident::query()
            ->withCount(['pmCases', 'amFiles'])
            ->orderByDesc('incident_date')
            ->get();

        return response()->json(['success' => true, 'incidents' => $incidents]);
    }

    /**
     * Opens a new incident. This is the first step of the Incident Pipeline:
     * bodies can only be logged against an incident that already exists.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'incident_id' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', 'unique:incident,incident_id'],
            'name' => ['required', 'string', 'max:255'],
            'incident_date' => ['required', 'date'],
            'district' => ['nullable', 'string', 'max:255'],
        ]);

        $incident = Incident::create([
            'incident_id' => $data['incident_id'],
            'name' => $data['name'],
            'incident_date' => $data['incident_date'],
            'district' => $data['district'] ?? null,
            // Real incidents opened through the app are never part of the
            // synthetic demo dataset.
            'synthetic' => false,
        ]);

        return response()->json(['success' => true, 'incident' => $incident], 201);
    }

    public function show(Incident $incident)
    {
        $run = $incident->latestRun();

        return response()->json([
            'success' => true,
            'incident' => $incident->loadCount(['pmCases', 'amFiles']),
            'run' => $run,
            'stats' => $this->stats->stats($incident, $run),
            'languages' => Observation::query()
                ->selectRaw('lang, count(*) as total')
                ->groupBy('lang')
                ->pluck('total', 'lang'),
            'breakdowns' => $this->stats->breakdowns($incident),
        ]);
    }
}
