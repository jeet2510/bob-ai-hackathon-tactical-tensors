<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Assistant\DviAssistant;
use Illuminate\Http\Request;

/**
 * "DVI Assistant" — the dashboard briefing and the chat sidebar. Both are
 * read-only and always return 200 (ai_available signals whether Gemini
 * actually answered), the same graceful-degradation contract as the intake
 * form's AI-assist panels: an assistant with no credentials configured must
 * never break the page it lives on.
 */
class AssistantController extends Controller
{
    public function insight(Incident $incident, DviAssistant $assistant)
    {
        $result = $assistant->insight($incident);

        return response()->json([
            'success' => true,
            'ai_available' => $result['ai_available'],
            'text' => $result['text'],
            'reason' => $result['reason'],
        ]);
    }

    public function chat(Request $request, Incident $incident, DviAssistant $assistant)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:2000'],
        ]);

        $result = $assistant->reply($incident, $data['message'], $data['history'] ?? []);

        return response()->json([
            'success' => true,
            'ai_available' => $result['ai_available'],
            'reply' => $result['text'],
            'reason' => $result['reason'],
        ]);
    }
}
