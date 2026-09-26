<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Replay store for model calls.
 *
 * Every extractor routes through this, so a pipeline run reproduces exactly
 * without network access — which matters both for a demo that cannot depend on
 * connectivity and for an audit that must be able to re-derive how a candidate
 * list was produced.
 */
class LlmCache extends Model
{
    protected $table = 'llm_cache';

    protected $guarded = [];

    protected $casts = [
        'output_json' => 'array',
    ];

    /**
     * Cache key. Includes the prompt version so that editing a prompt yields a
     * fresh entry rather than silently reusing output from the old one.
     */
    public static function keyFor(string $input): string
    {
        return hash('sha256', $input);
    }

    /**
     * @return array<mixed>|null
     */
    public static function lookup(string $input, string $promptVersion): ?array
    {
        return static::query()
            ->where('input_hash', self::keyFor($input))
            ->where('prompt_version', $promptVersion)
            ->value('output_json');
    }

    /**
     * @param  array<mixed>  $output
     */
    public static function remember(string $input, string $promptVersion, array $output, ?string $model = null): void
    {
        static::updateOrCreate(
            ['input_hash' => self::keyFor($input), 'prompt_version' => $promptVersion],
            ['output_json' => $output, 'model' => $model],
        );
    }
}
