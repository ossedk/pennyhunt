<?php

namespace App\Services\Nlp;

use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;

/**
 * Extracts ticker mentions from forum text.
 *
 * Confidence tiers:
 *  - 1.0  $CASHTAG (explicit)
 *  - 0.7  bare uppercase symbol validated against the active ticker universe
 *  - bare matches of ambiguous symbols (CEO, DD, YOLO...) are dropped entirely;
 *    those only count as cashtags.
 */
class TickerExtractor
{
    /**
     * @return array<string, array{confidence: float, method: string}> keyed by symbol
     */
    public function extract(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];

        // Tier 1: cashtags — $ABCD (1-5 letters, optional .X suffix)
        preg_match_all('/\$([A-Za-z]{1,5})(?:\.[A-Za-z])?\b/', $text, $cashtagMatches);
        foreach ($cashtagMatches[1] as $symbol) {
            $symbol = strtoupper($symbol);
            if ($this->isKnownSymbol($symbol)) {
                $found[$symbol] = ['confidence' => 1.0, 'method' => 'cashtag'];
            }
        }

        // Tier 2: bare uppercase words that are valid, unambiguous symbols
        preg_match_all('/(?<![\$\w])([A-Z]{2,5})(?![\w])/', $text, $bareMatches);
        foreach (array_unique($bareMatches[1]) as $symbol) {
            if (isset($found[$symbol])) {
                continue;
            }
            if ($this->isAmbiguous($symbol)) {
                continue;
            }
            if ($this->isKnownSymbol($symbol)) {
                $found[$symbol] = ['confidence' => 0.7, 'method' => 'symbol'];
            }
        }

        return $found;
    }

    protected function isKnownSymbol(string $symbol): bool
    {
        return in_array($symbol, $this->activeSymbols(), true);
    }

    protected function isAmbiguous(string $symbol): bool
    {
        return in_array($symbol, config('pennyhunt.ambiguous_symbols'), true);
    }

    /**
     * @return list<string>
     */
    protected function activeSymbols(): array
    {
        return Cache::remember(
            'tickers:active_symbols',
            now()->addMinutes(30),
            fn (): array => Ticker::query()->where('is_active', true)->pluck('symbol')->all(),
        );
    }
}
