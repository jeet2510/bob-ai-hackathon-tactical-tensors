<?php

namespace App\Console\Commands;

use App\Models\ObservationNorm;
use App\Models\PhotoEvidence;
use App\Services\Evaluation\GoldOracleExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Checks the invariants that make this system's numbers mean anything.
 *
 * All three are easy to break by accident and impossible to notice
 * afterwards: a pipeline that has quietly read the answer key still produces
 * plausible output, it just produces meaningless accuracy figures. This
 * command is cheap enough to run in CI and on every demo.
 */
class DviDoctor extends Command
{
    protected $signature = 'dvi:doctor';

    protected $description = 'Verify ground-truth isolation, face-photo policy and run integrity';

    /**
     * Namespaces that must never reach for ground truth. Evaluation is
     * deliberately absent: reading truth is its whole job.
     */
    protected const QUARANTINED = [
        'app/Http',
        'app/Services/Extraction',
        'app/Services/Matching',
        'app/Services/Reporting',
        'app/Models',
    ];

    public function handle(): int
    {
        $failures = [];

        $this->info('DVI integrity checks');
        $this->newLine();

        $failures = array_merge(
            $failures,
            $this->checkGroundTruthIsolation(),
            $this->checkFacePolicy(),
            $this->checkOracleNotDefault(),
        );

        $this->newLine();

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error("  ✗ {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('  All integrity checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function checkGroundTruthIsolation(): array
    {
        $offenders = [];

        foreach (self::QUARANTINED as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = $file->getContents();

                foreach (['GroundTruth', 'GoldOracleExtractor', 'ground_truth', 'truth_links', 'gold_observation_norm'] as $needle) {
                    if (str_contains($contents, $needle)) {
                        $relative = str_replace(base_path().'/', '', $file->getPathname());

                        // The evaluation controller is the sanctioned exception;
                        // it reads truth solely to compare against it.
                        if (str_contains($relative, 'EvaluationController')) {
                            continue;
                        }

                        $offenders[] = "{$relative} references '{$needle}'";
                    }
                }
            }
        }

        $this->line($offenders === []
            ? '  ✓ Ground truth is not reachable from the pipeline or the API.'
            : '  ✗ Ground truth leaked into application code:');

        return array_unique($offenders);
    }

    /**
     * @return list<string>
     */
    protected function checkFacePolicy(): array
    {
        $failures = [];

        $servableFaces = PhotoEvidence::where('modality', PhotoEvidence::MODALITY_FACE)
            ->where(fn ($q) => $q->whereNotNull('file_path')->where('file_path', '!=', ''))
            ->count();

        if ($servableFaces > 0) {
            $failures[] = "{$servableFaces} facial photographs carry a file path; they must never be servable.";
        }

        $misPolicied = PhotoEvidence::where('modality', PhotoEvidence::MODALITY_FACE)
            ->where('use_policy', '!=', PhotoEvidence::POLICY_RESTRICTED)
            ->count();

        if ($misPolicied > 0) {
            $failures[] = "{$misPolicied} facial photographs are not marked restricted.";
        }

        $total = PhotoEvidence::where('modality', PhotoEvidence::MODALITY_FACE)->count();

        $this->line($failures === []
            ? "  ✓ All {$total} facial photographs are restricted and unservable."
            : '  ✗ Facial-photograph policy violated:');

        return $failures;
    }

    /**
     * @return list<string>
     */
    protected function checkOracleNotDefault(): array
    {
        $failures = [];

        $oracleItems = ObservationNorm::where('extractor_version', GoldOracleExtractor::VERSION)->count();
        $realItems = ObservationNorm::where('extractor_version', '!=', GoldOracleExtractor::VERSION)->count();

        if ($oracleItems > 0 && $realItems === 0) {
            $failures[] = 'The only normalised items present came from the gold oracle. '
                .'Run dvi:extract with a real extractor before showing any system result.';
        }

        $this->line($oracleItems > 0
            ? "  ✓ Oracle items present ({$oracleItems}) alongside {$realItems} real items — labelled and separable."
            : "  ✓ No oracle items present; {$realItems} real items.");

        return $failures;
    }
}
