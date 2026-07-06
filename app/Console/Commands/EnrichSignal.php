<?php

namespace App\Console\Commands;

use App\Models\Signal;
use App\Models\SignalModel;
use App\Services\Features\LlmAggregates;
use App\Services\Features\MarketIntelligence;
use App\Services\Features\SectorHeat;
use App\Services\Features\TechnicalFeatures;
use App\Services\Ml\ConfidenceTrainer;
use App\Services\Nlp\SignalBriefWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-enriches an existing signal with the CURRENT feature stack, as-of its
 * original fire date (no look-ahead): dilution/short-flow/macro intel, LLM
 * crowd aggregates, sector heat, bar technicals — then regenerates the LLM
 * "what to look for" brief. The original components/market-gate stay
 * untouched; this only upgrades the stored breakdown to today's schema.
 */
class EnrichSignal extends Command
{
    protected $signature = 'pennyhunt:enrich-signal
        {signal : Signal id to enrich}
        {--no-brief : Skip the LLM brief regeneration}';

    protected $description = 'Recompute a signal\'s feature breakdown with the current stack (as-of fire date) and regenerate its LLM brief';

    public function handle(SignalBriefWriter $briefWriter): int
    {
        $signal = Signal::findOrFail((int) $this->argument('signal'));
        $day = $signal->fired_at->toDateString();

        $this->info("Enriching signal #{$signal->id} ({$signal->ticker->symbol}, fired {$day})...");

        $intel = MarketIntelligence::load([$signal->ticker_id], $day, $day)
            ->features($signal->ticker_id, $day);

        $llm = LlmAggregates::load([$signal->ticker_id], $day, $day)
            ->features($signal->ticker_id, $day);

        $sector = SectorHeat::loadForDay([$signal->ticker_id], $day)
            ->features($signal->ticker_id, $day);

        // Technicals from the bars available AT the fire date.
        $bars = DB::table('market_bars')
            ->where('ticker_id', $signal->ticker_id)
            ->where('interval', '1d')
            ->where('bucket_start', '<=', $day.' 23:59:59')
            ->orderByDesc('bucket_start')
            ->limit(260)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($b): array => [
                'date' => substr((string) $b->bucket_start, 0, 10),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'volume' => (float) $b->volume,
            ])
            ->all();

        $technicals = $bars !== []
            ? TechnicalFeatures::compute($bars, count($bars) - 1)
            : array_fill_keys(TechnicalFeatures::FEATURE_KEYS, null);

        $signal->forceFill([
            'breakdown' => [
                ...($signal->breakdown ?? []),
                'intel' => $intel,
                'llm' => $llm,
                'sector' => $sector,
                'technicals' => $technicals,
                'enriched_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->line('  breakdown upgraded: intel('.count($intel).') llm('.count($llm).') sector('.count($sector).') technicals('.count($technicals).')');

        // Retro-score with the ACTIVE models using the as-of feature vector.
        // Marked as retro in model_version — this is today's model looking
        // back, not what fired at the time.
        $inputs = data_get($signal->breakdown, 'inputs', []);
        $gate = data_get($signal->breakdown, 'market_gate', []);

        $features = ConfidenceTrainer::features([
            'zscore' => (float) ($inputs['zscore_mentions'] ?? 0.0),
            'volume_z' => $gate['volume_z'] ?? null,
            'sentiment' => $inputs['weighted_sentiment'] ?? null,
            'unique_authors' => (int) ($inputs['unique_authors'] ?? 0),
            'mentions' => (int) ($inputs['mention_count'] ?? 0),
            'pre_return_3d' => $gate['pre_return_3d'] ?? null,
            'dollar_volume' => $gate['dollar_volume'] ?? null,
            ...$technicals,
            ...$intel,
            ...$llm,
            ...$sector,
        ]);

        $model = SignalModel::active();
        $moonshot = SignalModel::activeMoonshot();

        if ($model !== null) {
            $confidence = $model->predict($features);
            $moonshotP = $moonshot?->predict($features);

            $signal->forceFill([
                'confidence' => $confidence,
                'model_version' => $model->version.' (retro)',
                'breakdown' => [
                    ...$signal->breakdown,
                    'moonshot_p' => $moonshotP,
                ],
            ])->save();

            $this->line(sprintf(
                '  retro-scored: confidence %.3f (%s)%s',
                $confidence,
                $model->version,
                $moonshotP !== null ? sprintf(', moonshot_p %.3f', $moonshotP) : '',
            ));
        }

        if (! $this->option('no-brief')) {
            $signal->forceFill(['llm_brief' => null])->save();
            $brief = $briefWriter->write($signal->refresh());
            $this->line($brief !== null ? '  LLM brief regenerated.' : '  LLM brief skipped (no key or unusable output).');
        }

        return self::SUCCESS;
    }
}
