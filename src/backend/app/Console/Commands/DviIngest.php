<?php

namespace App\Console\Commands;

use App\Models\AmFile;
use App\Models\Incident;
use App\Models\Observation;
use App\Models\PhotoEvidence;
use App\Models\PmCase;
use App\Support\Csv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DviIngest extends Command
{
    protected $signature = 'dvi:ingest
        {--data= : Path to the dataset data/ directory}
        {--fresh : Delete existing DVI records before ingesting}';

    protected $description = 'Load the DVI dataset CSVs (incident, PM, AM, observations, photos) into the database';

    /**
     * Ground truth must never be reachable from the application. The ingest is
     * the one place tempted to read it — seeding normalised items from
     * `gold_observation_norm.jsonl` would make every accuracy figure the
     * system later reports meaningless. Guard it explicitly.
     */
    protected const FORBIDDEN_PATH = 'ground_truth';

    public function handle(): int
    {
        $dir = rtrim($this->option('data') ?: config('dvi.data_path'), '/');

        if (str_contains($dir, self::FORBIDDEN_PATH)) {
            $this->error('Refusing to ingest from a ground_truth path. Ground truth is for evaluation only.');

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            $this->error("Dataset directory not found: {$dir}");
            $this->line('Pass --data=/path/to/dataset/data');

            return self::FAILURE;
        }

        $this->info("Ingesting from {$dir}");

        if ($this->option('fresh')) {
            $this->truncate();
        }

        $counts = [];

        DB::transaction(function () use ($dir, &$counts) {
            $counts['incident'] = $this->ingestIncidents("{$dir}/incident.csv");
            $counts['pm_case'] = $this->ingestPmCases("{$dir}/pm_case.csv");
            $counts['am_file'] = $this->ingestAmFiles("{$dir}/am_file.csv");
            $counts['observation'] = $this->ingestObservations("{$dir}/observation.csv");
            $counts['photo_evidence'] = $this->ingestPhotos("{$dir}/photo_evidence.csv");
        });

        $this->newLine();
        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all(),
        );

        $restricted = PhotoEvidence::where('use_policy', PhotoEvidence::POLICY_RESTRICTED)->count();
        $matchable = PhotoEvidence::matchable()->count();

        $this->line("  {$matchable} photos are matchable; {$restricted} are restricted display-only and will never be scored.");

        return self::SUCCESS;
    }

    protected function truncate(): void
    {
        // Ordered so foreign keys are satisfied without disabling constraints.
        foreach (['photo_evidence', 'observation', 'am_file', 'pm_case', 'incident'] as $table) {
            DB::table($table)->delete();
        }

        $this->warn('Existing DVI records deleted.');
    }

    protected function ingestIncidents(string $path): int
    {
        $n = 0;

        foreach (Csv::rows($path) as $row) {
            Incident::updateOrCreate(
                ['incident_id' => $row['incident_id']],
                [
                    'name' => $row['name'],
                    'incident_date' => $row['incident_date'],
                    'district' => Csv::nullable($row['district'] ?? null),
                    'synthetic' => Csv::bool($row['synthetic'] ?? '1'),
                ],
            );
            $n++;
        }

        return $n;
    }

    protected function ingestPmCases(string $path): int
    {
        $n = 0;

        foreach (Csv::rows($path) as $row) {
            PmCase::updateOrCreate(
                ['pm_id' => $row['pm_id']],
                [
                    'incident_id' => $row['incident_id'],
                    'found_at' => $row['found_at'],
                    'found_place' => Csv::nullable($row['found_place']),
                    'lat' => Csv::float($row['lat']),
                    'lon' => Csv::float($row['lon']),
                    'body_condition' => Csv::nullable($row['body_condition']),
                    'sex' => Csv::nullable($row['sex']),
                    'age_min' => Csv::int($row['age_min']),
                    'age_max' => Csv::int($row['age_max']),
                    'height_cm' => Csv::int($row['height_cm']),
                    'dna_status' => Csv::nullable($row['dna_status']),
                    'dental_status' => Csv::nullable($row['dental_status']),
                    'print_status' => Csv::nullable($row['print_status']),
                    'dental_chart_fdi' => Csv::nullable($row['dental_chart_fdi']),
                    'synthetic' => Csv::bool($row['synthetic']),
                ],
            );
            $n++;
        }

        return $n;
    }

    protected function ingestAmFiles(string $path): int
    {
        $n = 0;

        foreach (Csv::rows($path) as $row) {
            AmFile::updateOrCreate(
                ['am_id' => $row['am_id']],
                [
                    // am_file.csv carries no incident column; the identifier
                    // encodes it (AM-LS26-007 -> LS26).
                    'incident_id' => $this->incidentFromId($row['am_id']),
                    'reported_name' => $row['reported_name'],
                    'sex' => Csv::nullable($row['sex']),
                    'age' => Csv::int($row['age']),
                    'height_text' => Csv::nullable($row['height_text']),
                    'height_cm_reported' => Csv::int($row['height_cm_reported']),
                    'last_seen_at' => Csv::nullable($row['last_seen_at']),
                    'last_seen_place' => Csv::nullable($row['last_seen_place']),
                    'reported_by_relation' => Csv::nullable($row['reported_by_relation']),
                    'occupation' => Csv::nullable($row['occupation']),
                    'marital_status' => Csv::nullable($row['marital_status']),
                    'dna_reference_type' => Csv::nullable($row['dna_reference_type']),
                    'dental_records_available' => Csv::bool($row['dental_records_available']),
                    'prints_on_file' => Csv::bool($row['prints_on_file']),
                    'dental_chart_fdi' => Csv::nullable($row['dental_chart_fdi']),
                    'synthetic' => Csv::bool($row['synthetic']),
                ],
            );
            $n++;
        }

        return $n;
    }

    protected function ingestObservations(string $path): int
    {
        $n = 0;
        $batch = [];
        $now = now();

        foreach (Csv::rows($path) as $row) {
            $batch[] = [
                'obs_id' => $row['obs_id'],
                'record_type' => $row['record_type'],
                'record_id' => $row['record_id'],
                'category' => $row['category'],
                'form_field' => Csv::nullable($row['form_field']),
                'raw_text' => $row['raw_text'],
                'lang' => $row['lang'] ?: 'en',
                'source_type' => Csv::nullable($row['source_type']),
                'recorded_by' => Csv::nullable($row['recorded_by']),
                'recorded_at' => Csv::nullable($row['recorded_at']),
                'reliability_note' => Csv::nullable($row['reliability_note']),
                'synthetic' => Csv::bool($row['synthetic']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $n++;

            if (count($batch) >= 500) {
                Observation::upsert($batch, ['obs_id']);
                $batch = [];
            }
        }

        if ($batch !== []) {
            Observation::upsert($batch, ['obs_id']);
        }

        return $n;
    }

    protected function ingestPhotos(string $path): int
    {
        $n = 0;
        $batch = [];
        $now = now();

        foreach (Csv::rows($path) as $row) {
            $batch[] = [
                'photo_id' => $row['photo_id'],
                'record_type' => $row['record_type'],
                'record_id' => $row['record_id'],
                'modality' => $row['modality'],
                'view' => Csv::nullable($row['view']),
                'captured_at' => Csv::nullable($row['captured_at']),
                'source_type' => Csv::nullable($row['source_type']),
                'quality_flags' => Csv::nullable($row['quality_flags']),
                'file_path' => Csv::nullable($row['file_path']),
                'use_policy' => $row['use_policy'],
                'reliability_note' => Csv::nullable($row['reliability_note']),
                'sha256' => Csv::nullable($row['sha256']),
                'synthetic' => Csv::bool($row['synthetic']),
                'created_at' => $now,
                'updated_at' => $now,
                // `withheld_from_text` is present in the CSV and deliberately
                // not read: it is derived from ground truth and exists only so
                // the evaluation can measure what a text-only pipeline missed.
            ];
            $n++;

            if (count($batch) >= 500) {
                PhotoEvidence::upsert($batch, ['photo_id']);
                $batch = [];
            }
        }

        if ($batch !== []) {
            PhotoEvidence::upsert($batch, ['photo_id']);
        }

        return $n;
    }

    /**
     * AM-LS26-007 -> LS26
     */
    protected function incidentFromId(string $id): ?string
    {
        $parts = explode('-', $id);

        return $parts[1] ?? null;
    }
}
