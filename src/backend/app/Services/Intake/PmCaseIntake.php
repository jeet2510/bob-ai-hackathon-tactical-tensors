<?php

namespace App\Services\Intake;

use App\Models\Incident;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use Illuminate\Support\Facades\DB;

/**
 * Turns one submitted post-mortem intake form (sections A–K of the DVI field
 * form) into the rows the rest of the system already knows how to read.
 *
 * Shared item-writing logic (appearance, marks/tattoo/implant, clothing,
 * jewellery, id documents) lives in RecordIntake — AmFileIntake writes the
 * exact same shapes for the ante-mortem side. Only the scalar header fields
 * and the two PM-only sections (dental chart, the fuller photo evidence log)
 * are specific to this class.
 */
class PmCaseIntake extends RecordIntake
{
    /** Paper photo-log modality label -> photo_evidence.modality. */
    public const PHOTO_MODALITY_MAP = [
        'Body diagram' => 'body_diagram',
        'Tattoo/mark' => 'tattoo_closeup',
        'Clothing' => 'clothing_flatlay',
        'Face (restr.)' => PhotoEvidence::MODALITY_FACE,
        'Other' => 'other',
    ];

    /**
     * @param  array<string, mixed>  $data  validated request payload
     * @param  array<string, string>  $stagedFiles  upload_ref => absolute path of a file staged via UploadController
     */
    public function store(Incident $incident, array $data, array $stagedFiles = []): PmCase
    {
        return DB::transaction(function () use ($incident, $data, $stagedFiles) {
            $pmId = $data['pm_id'] ?? $this->nextPmId($incident->incident_id);
            $sourceType = $data['source'] ?? 'manual';

            $pm = PmCase::create([
                'pm_id' => $pmId,
                'incident_id' => $incident->incident_id,
                'examiner_name' => $data['examiner_name'],
                'examiner_role' => $data['examiner_role'],
                'found_at' => $data['found_at'],
                'found_place' => $data['found_place'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lon' => $data['lon'] ?? null,
                'body_condition' => $data['body_condition'],
                'sex' => $data['sex'],
                'age_min' => $data['age_min'],
                'age_max' => $data['age_max'],
                'height_cm' => $data['height_cm'] ?? null,
                'dna_status' => $data['dna_status'],
                'dental_status' => $data['dental_status'],
                'print_status' => $data['print_status'],
                'dental_chart_fdi' => $this->dentalChartJson($data['dental_chart'] ?? []),
                'signature_note' => $data['signature_note'] ?? null,
                'completed_at' => $data['completed_at'] ?? null,
                'chain_of_custody_hash' => $data['chain_of_custody_hash'] ?? null,
                'source_type' => $sourceType,
                'synthetic' => false,
            ]);

            $reviewer = $data['examiner_role'];

            $this->writeAppearance('PM', $pm->pm_id, $data, $reviewer, $sourceType);
            $this->writeDistinguishingFeatures('PM', $pm->pm_id, $data['distinguishing_features'] ?? [], $reviewer, $sourceType);
            $this->writeClothing('PM', $pm->pm_id, $data['clothing'] ?? [], $reviewer, $sourceType);
            $this->writeJewelleryEffects('PM', $pm->pm_id, $data['jewellery_effects'] ?? [], $reviewer, $sourceType);
            $this->writeIdDocuments('PM', $pm->pm_id, $data['id_documents'] ?? [], $reviewer, $sourceType);
            $this->writeNotes('PM', $pm->pm_id, $data['notes'] ?? null, $reviewer, $sourceType, 'Additional notes / observations');
            $this->writePhotoLog($incident->incident_id, $pm, $data['photo_log'] ?? [], $reviewer, $sourceType, $stagedFiles);

            return $pm->fresh();
        });
    }

    /**
     * @param  list<array{tooth: int, code: string}>  $rows
     */
    protected function dentalChartJson(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $chart = ['missing' => [], 'filled' => [], 'crown' => [], 'root_canal' => []];
        $codeToState = ['M' => 'missing', 'F' => 'filled', 'C' => 'crown', 'R' => 'root_canal'];

        foreach ($rows as $row) {
            $state = $codeToState[$row['code']] ?? null;

            if ($state !== null) {
                $chart[$state][] = (int) $row['tooth'];
            }
        }

        return json_encode($chart);
    }

    /**
     * Section J: the photo evidence log. Section F per-item photos land here
     * too once they carry an upload_ref.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $stagedFiles
     */
    protected function writePhotoLog(string $incidentId, PmCase $pm, array $rows, string $reviewer, string $sourceType, array $stagedFiles): void
    {
        foreach ($rows as $row) {
            $modality = self::PHOTO_MODALITY_MAP[$row['modality'] ?? ''] ?? 'other';
            $restricted = $modality === PhotoEvidence::MODALITY_FACE;

            $filePath = null;

            if (filled($row['upload_ref'] ?? null) && isset($stagedFiles[$row['upload_ref']]) && ! $restricted) {
                $filePath = $this->promoteUpload("incidents/{$incidentId}/bodies/{$pm->pm_id}/photos", $stagedFiles[$row['upload_ref']]);
            }

            $this->createPhoto('PM', $pm->pm_id, [
                'modality' => $modality,
                'view' => $row['view'] ?? null,
                'captured_at' => now(),
                'source_type' => $sourceType,
                'quality_flags' => implode(',', $row['quality_flags'] ?? []),
                'file_path' => $filePath,
                'use_policy' => $restricted
                    ? PhotoEvidence::POLICY_RESTRICTED
                    : 'extract_items_then_human_confirm',
                'sha256' => $filePath ? hash_file('sha256', storage_path('app/private/'.$filePath)) : null,
            ]);
        }
    }

    protected function nextPmId(string $incidentId): string
    {
        $count = PmCase::where('incident_id', $incidentId)->count();

        do {
            $count++;
            $candidate = sprintf('PM-%s-%03d', $incidentId, $count);
        } while (PmCase::whereKey($candidate)->exists());

        return $candidate;
    }
}
