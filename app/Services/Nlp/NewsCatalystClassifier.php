<?php

namespace App\Services\Nlp;

use App\Models\TickerNews;
use Illuminate\Support\Facades\Http;

/**
 * Batch LLM classification of news headlines into catalyst types, so
 * "FDA approval" and "$50M registered direct offering" stop being the same
 * feature. 25 headlines per call keeps cost negligible.
 *
 * Types: fda | clinical | merger | contract | partnership | earnings |
 * uplisting | product | short_report | legal | offering | none.
 * "offering" is the bearish one (dilution) — everything else except
 * none/other counts as a positive catalyst for the model.
 */
class NewsCatalystClassifier
{
    public const TYPES = [
        'fda', 'clinical', 'merger', 'contract', 'partnership', 'earnings',
        'uplisting', 'product', 'short_report', 'legal', 'offering', 'none',
    ];

    protected const BATCH = 25;

    protected const SYSTEM_PROMPT = <<<'PROMPT'
You classify stock-news headlines into catalyst types. For each numbered headline respond with the number and exactly one type:
- "fda": FDA approval/clearance/designation (or equivalent regulator)
- "clinical": trial results, enrollment, data readouts
- "merger": M&A, acquisition, takeover, going private, strategic review
- "contract": contract award, purchase order, government deal
- "partnership": partnership, collaboration, licensing deal
- "earnings": earnings/revenue results or guidance
- "uplisting": exchange uplisting, listing standards regained
- "product": product launch, milestone, delivery
- "short_report": short-seller report, fraud allegation
- "legal": lawsuit, investigation, settlement
- "offering": share offering, registered direct, ATM, warrant exercise, dilution, reverse split
- "none": analyst notes, stock commentary, price-move recaps, anything else
Respond with ONLY a JSON object: {"1": "type", "2": "type", ...} — one entry per headline.
PROMPT;

    public function enabled(): bool
    {
        return filled(config('pennyhunt.llm.openai_api_key'));
    }

    /**
     * Classify up to $limit unclassified articles. Returns rows classified.
     */
    public function classifyPending(int $limit = 500): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $done = 0;

        while ($done < $limit) {
            $batch = TickerNews::query()
                ->whereNull('catalyst_classified_at')
                ->orderByDesc('published_at')
                ->limit(min(self::BATCH, $limit - $done))
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $verdicts = $this->classifyBatch($batch->pluck('title')->all());

            foreach ($batch->values() as $i => $article) {
                $article->forceFill([
                    'catalyst_type' => $verdicts[$i] ?? 'none',
                    'catalyst_classified_at' => now(),
                ])->save();
            }

            $done += $batch->count();
        }

        return $done;
    }

    /**
     * @param  array<int, string|null>  $titles
     * @return array<int, string> catalyst type per input index
     */
    public function classifyBatch(array $titles): array
    {
        $numbered = [];

        foreach (array_values($titles) as $i => $title) {
            $numbered[] = ($i + 1).'. '.mb_substr((string) $title, 0, 200);
        }

        $response = Http::timeout(60)
            ->withToken((string) config('pennyhunt.llm.openai_api_key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('pennyhunt.llm.openai_model'),
                'reasoning_effort' => 'minimal',
                'max_completion_tokens' => 2000,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => implode("\n", $numbered)],
                ],
            ]);

        $json = json_decode((string) $response->json('choices.0.message.content'), true);

        $out = [];

        foreach (array_values($titles) as $i => $_) {
            $type = is_array($json) ? ($json[(string) ($i + 1)] ?? 'none') : 'none';
            $out[$i] = in_array($type, self::TYPES, true) ? $type : 'none';
        }

        return $out;
    }
}
