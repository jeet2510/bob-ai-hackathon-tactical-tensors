<?php

namespace App\Services\Intake;

use App\Models\AmFile;
use App\Models\Incident;
use App\Models\PhotoEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Turns one submitted ante-mortem family interview form (sections A–I of
 * the DVI family interview form) into the rows the rest of the system
 * already knows how to read — the AM-side counterpart to PmCaseIntake,
 * sharing the same item-writing logic via RecordIntake.
 *
 * `$sourceType` is always passed in by the caller (never trusted from the
 * submitted payload): 'family_self_report' for the public share-link path,
 * 'staff_interview' for a coordinator entering a phoned-in report.
 */
class AmFileIntake extends RecordIntake
{
    /**
     * Shared by both the staff (authenticated) and public (share-link)
     * submission endpoints, so the two can never validate a submission
     * differently.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'am_id' => ['nullable', 'string', 'max:40'],
            'reported_name' => ['required', 'string', 'max:255'],
            'sex' => ['nullable', Rule::in(['M', 'F'])],
            'age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'height_text' => ['nullable', 'string', 'max:60'],
            'height_cm_reported' => ['nullable', 'integer', 'min:30', 'max:250'],
            'last_seen_at' => ['nullable', 'date'],
            'last_seen_place' => ['nullable', 'string', 'max:255'],
            'reported_by_relation' => ['nullable', 'string', 'max:100'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'marital_status' => ['nullable', Rule::in(['married', 'single', 'widowed', 'divorced_separated'])],

            'build' => ['nullable', 'string', 'max:20'],
            'skin_tone' => ['nullable', 'string', 'max:20'],
            'skin_tone_other' => ['nullable', 'string', 'max:100'],
            'hair_colour' => ['nullable', 'string', 'max:20'],
            'hair_length' => ['nullable', 'string', 'max:20'],
            'eye_colour' => ['nullable', 'string', 'max:20'],
            'facial_hair' => ['nullable', 'string', 'max:30'],

            'dna_reference_type' => ['nullable', Rule::in(['family_reference', 'personal_item'])],
            'dental_records_available' => ['nullable', 'boolean'],
            'dentist_contact' => ['nullable', 'string', 'max:255'],
            'prints_on_file' => ['nullable', 'boolean'],
            'id_sources' => ['nullable', 'array'],
            'id_sources.*' => ['string', Rule::in(['Passport', 'Aadhaar / national ID', 'Driving licence', 'Prior police record'])],

            'recent_photo_ref' => ['nullable', 'string', 'max:80'],
            'tattoo_photo_ref' => ['nullable', 'string', 'max:80'],

            'distinguishing_features' => ['nullable', 'array', 'max:6'],
            'distinguishing_features.*.type' => ['required_with:distinguishing_features', 'string',
                Rule::in(['tattoo', 'mark', 'scar', 'mole', 'birthmark', 'burn', 'deformity', 'amputation', 'piercing', 'implant', 'other'])],
            'distinguishing_features.*.description' => ['nullable', 'string', 'max:500'],
            'distinguishing_features.*.region' => ['nullable', 'string', 'max:40'],
            'distinguishing_features.*.side' => ['nullable', Rule::in(['L', 'R', 'C'])],
            'distinguishing_features.*.serial_no' => ['nullable', 'string', 'max:120'],

            'clothing' => ['nullable', 'array', 'max:5'],
            'clothing.*.slot' => ['required_with:clothing', Rule::in(['Headwear', 'Upper body', 'Lower body', 'Footwear', 'Other'])],
            'clothing.*.garment' => ['nullable', 'string', 'max:60'],
            'clothing.*.colour' => ['nullable', 'string', 'max:40'],

            'jewellery_effects' => ['nullable', 'array', 'max:5'],
            'jewellery_effects.*.kind' => ['required_with:jewellery_effects', Rule::in(['jewellery', 'belonging'])],
            'jewellery_effects.*.item' => ['nullable', 'string', 'max:120'],
            'jewellery_effects.*.material_description' => ['nullable', 'string', 'max:255'],

            'id_documents' => ['nullable', 'array'],
            'id_documents.*.document_type' => ['nullable', 'string', 'max:60'],
            'id_documents.*.id_last4' => ['nullable', 'string', 'max:4'],
            'id_documents.*.name_on_document' => ['nullable', 'string', 'max:255'],
            'id_documents.*.note' => ['nullable', 'string', 'max:255'],

            'notes' => ['nullable', 'string', 'max:4000'],

            'reporter_name' => ['required', 'string', 'max:255'],
            'reporter_phone' => ['nullable', 'string', 'max:40'],
            'reporter_address' => ['nullable', 'string', 'max:255'],
            'interviewing_officer' => ['nullable', 'string', 'max:255'],
            'interviewed_at' => ['nullable', 'date'],
        ];
    }

    public function store(Incident $incident, array $data, array $stagedFiles, string $sourceType): AmFile
    {
        return DB::transaction(function () use ($incident, $data, $stagedFiles, $sourceType) {
            $amId = $data['am_id'] ?? $this->nextAmId($incident->incident_id);

            $am = AmFile::create([
                'am_id' => $amId,
                'incident_id' => $incident->incident_id,
                'reported_name' => $data['reported_name'],
                'sex' => $data['sex'] ?? null,
                'age' => $data['age'] ?? null,
                'height_text' => $data['height_text'] ?? null,
                'height_cm_reported' => $data['height_cm_reported'] ?? null,
                'last_seen_at' => $data['last_seen_at'] ?? null,
                'last_seen_place' => $data['last_seen_place'] ?? null,
                'reported_by_relation' => $data['reported_by_relation'] ?? null,
                'occupation' => $data['occupation'] ?? null,
                'marital_status' => $data['marital_status'] ?? null,
                'dna_reference_type' => $data['dna_reference_type'] ?? null,
                'dental_records_available' => (bool) ($data['dental_records_available'] ?? false),
                'dentist_contact' => $data['dentist_contact'] ?? null,
                'prints_on_file' => (bool) ($data['prints_on_file'] ?? false),
                'id_sources' => filled($data['id_sources'] ?? null) ? implode(', ', $data['id_sources']) : null,
                'reporter_name' => $data['reporter_name'] ?? null,
                'reporter_phone' => $data['reporter_phone'] ?? null,
                'reporter_address' => $data['reporter_address'] ?? null,
                'interviewing_officer' => $data['interviewing_officer'] ?? null,
                'interviewed_at' => $data['interviewed_at'] ?? null,
                'source_type' => $sourceType,
                'synthetic' => false,
            ]);

            $reviewer = $data['interviewing_officer'] ?? ($data['reporter_name'] ?? 'family report');

            $this->writeAppearance('AM', $am->am_id, $data, $reviewer, $sourceType);
            $this->writeDistinguishingFeatures('AM', $am->am_id, $data['distinguishing_features'] ?? [], $reviewer, $sourceType);
            $this->writeClothing('AM', $am->am_id, $data['clothing'] ?? [], $reviewer, $sourceType);
            $this->writeJewelleryEffects('AM', $am->am_id, $data['jewellery_effects'] ?? [], $reviewer, $sourceType);
            $this->writeIdDocuments('AM', $am->am_id, $data['id_documents'] ?? [], $reviewer, $sourceType);
            $this->writeNotes('AM', $am->am_id, $data['notes'] ?? null, $reviewer, $sourceType, 'Notes / anything else the family wants recorded');
            $this->writePhotos($incident->incident_id, $am->am_id, $data, $sourceType, $stagedFiles);

            return $am->fresh();
        });
    }

    /**
     * Section E: just two possible photographs — a recent photo (family
     * viewing only, never matched, same policy as the PM side's face photo)
     * and a tattoo/mark close-up (matchable, same as the PM side).
     */
    protected function writePhotos(string $incidentId, string $amId, array $data, string $sourceType, array $stagedFiles): void
    {
        $recentRef = $data['recent_photo_ref'] ?? null;

        if (filled($recentRef) && isset($stagedFiles[$recentRef])) {
            // Restricted photos carry no file, by the same policy the
            // seeded dataset already follows for face_photo_restricted —
            // PhotoEvidence::isMatchable() (and SupportController::photo())
            // refuse to ever serve this modality regardless of file_path,
            // so the upload is acknowledged but not persisted.
            $this->createPhoto('AM', $amId, [
                'modality' => PhotoEvidence::MODALITY_FACE,
                'view' => 'face',
                'captured_at' => now(),
                'source_type' => $sourceType,
                'file_path' => null,
                'use_policy' => PhotoEvidence::POLICY_RESTRICTED,
                'reliability_note' => 'Family-supplied recent photograph; display-only, never used for matching.',
            ]);
        }

        $tattooRef = $data['tattoo_photo_ref'] ?? null;

        if (filled($tattooRef) && isset($stagedFiles[$tattooRef])) {
            $filePath = $this->promoteUpload("incidents/{$incidentId}/profiles/{$amId}/photos", $stagedFiles[$tattooRef]);

            $this->createPhoto('AM', $amId, [
                'modality' => 'tattoo_photo',
                'view' => 'close-up',
                'captured_at' => now(),
                'source_type' => $sourceType,
                'file_path' => $filePath,
                'use_policy' => 'extract_items_then_human_confirm',
                'sha256' => $filePath ? hash_file('sha256', storage_path('app/private/'.$filePath)) : null,
            ]);
        }
    }

    protected function nextAmId(string $incidentId): string
    {
        $count = AmFile::where('incident_id', $incidentId)->count();

        do {
            $count++;
            $candidate = sprintf('AM-%s-%03d', $incidentId, $count);
        } while (AmFile::whereKey($candidate)->exists());

        return $candidate;
    }
}
