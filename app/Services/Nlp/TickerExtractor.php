<?php

namespace App\Services\Nlp;

use App\Models\Ticker;
use Illuminate\Support\Facades\Cache;

/**
 * Extracts ticker mentions from forum text — precision first: a false
 * mention poisons mention velocity, z-scores, signals AND the posts shown
 * on a ticker page ("$NOW HIT THE BOTTOM" must never count for $HIT).
 *
 * Confidence tiers:
 *  - 1.0  $CASHTAG (explicit; the only tier allowed for tweets)
 *  - 0.7  bare uppercase symbol that is NOT an English word (TSLA, ASTS)
 *  - 0.5  bare uppercase symbol that IS an English word (META, COIN, HIT),
 *         rescued only by an adjacent finance cue ("META calls",
 *         "bought COIN", "HIT stock")
 *
 * Guards on all bare matches:
 *  - curated ambiguous list (CEO, DD, YOLO...) → cashtag-only, no rescue
 *  - shouting guard: inside an ALL-CAPS run ("$NOW HIT THE BOTTOM AT $89")
 *    capitalization carries no signal, so bare matches are dropped
 */
class TickerExtractor
{
    /**
     * Noun cues that mark the token as a ticker when they IMMEDIATELY follow
     * it ("HIT shares", "META calls"). Position matters: "Earnings HIT
     * different" has the cue before a verb and must NOT rescue.
     */
    protected const CUES_AFTER = [
        'stock', 'stocks', 'share', 'shares', 'ticker', 'calls', 'puts', 'options',
        'warrants', 'earnings', 'dilution', 'offering', 'float', 'squeeze', 'shorts',
        'position', 'dd', 'chart', 'volume', 'price',
    ];

    /**
     * Trading verbs that mark the token as a ticker when they appear within
     * the two words BEFORE it ("bought more HIT", "holding META").
     */
    protected const CUES_BEFORE = [
        'buy', 'bought', 'buying', 'sell', 'sold', 'selling', 'long', 'short',
        'shorting', 'hold', 'holding', 'held', 'add', 'added', 'adding',
        'trim', 'trimmed', 'yolo', 'ticker',
    ];

    /**
     * Censored profanity where "$" stands in for "S" ("crock of $hit",
     * "$lut", "$ex tape") — family-filtered wordlists omit these, so the
     * dollar-as-S check needs them spelled out.
     */
    protected const DOLLAR_AS_S_WORDS = ['SHIT', 'SHITS', 'SLUT', 'SLUTS', 'SEX', 'SEXY', 'SUCK', 'SUCKS'];

    /**
     * A determiner/possessive/adjective immediately before a profanity-shaped
     * cashtag marks it as a censored noun ("STUPID $HIT", "your $hit list"),
     * even in uppercase — nobody writes "this $HIT" about a stock they hold.
     */
    protected const PROFANITY_PRECEDERS = [
        'this', 'that', 'the', 'these', 'those', 'your', 'my', 'his', 'her',
        'their', 'our', 'its', 'some', 'no', 'any', 'of', 'what', 'stupid',
        'dumb', 'crazy', 'holy', 'little', 'dog', 'bull', 'horse', 'ape',
        'piece', 'talking', 'talk', 'full',
    ];

    /**
     * @param  string|null  $sourceType  'twitter' restricts to cashtags: tweets
     *                                   use $cashtags by convention, so bare
     *                                   uppercase words there are shouting, not tickers.
     * @return array<string, array{confidence: float, method: string}> keyed by symbol
     */
    public function extract(string $text, ?string $sourceType = null): array
    {
        if (trim($text) === '') {
            return [];
        }

        $found = [];

        // Tier 1: cashtags — $ABCD (1-5 letters, optional .X suffix).
        // Lookbehind stops mid-word matches: "bull$hit" is not a $HIT tag.
        preg_match_all('/(?<![\w$])\$([A-Za-z]{1,5})(?:\.[A-Za-z])?\b/', $text, $cashtagMatches, PREG_OFFSET_CAPTURE);
        foreach ($cashtagMatches[1] as [$raw, $offset]) {
            $symbol = strtoupper($raw);

            if ($this->isDollarAsS($raw) || ! $this->isKnownSymbol($symbol)) {
                continue;
            }

            if ($this->isCensoredProfanity($text, $offset, $raw)) {
                continue;
            }

            $found[$symbol] = ['confidence' => 1.0, 'method' => 'cashtag'];
        }

        // Shout-heavy platforms: cashtags only. Stocktwits mentions arrive
        // via structured symbol tags in the ingestor anyway.
        if ($sourceType === 'twitter' || $sourceType === 'stocktwits') {
            return $found;
        }

        // Tier 2/3: bare uppercase words validated against the universe
        preg_match_all('/(?<![\$\w])([A-Z]{2,5})(?![\w])/', $text, $bareMatches, PREG_OFFSET_CAPTURE);

        foreach ($bareMatches[1] as [$symbol, $offset]) {
            if (isset($found[$symbol]) || $this->isAmbiguous($symbol) || ! $this->isKnownSymbol($symbol)) {
                continue;
            }

            if ($this->isShoutingContext($text, $offset, $symbol)) {
                continue;
            }

            if (! $this->isEnglishWord($symbol)) {
                $found[$symbol] = ['confidence' => 0.7, 'method' => 'symbol'];

                continue;
            }

            if ($this->hasAdjacentFinanceCue($text, $offset, $symbol)) {
                $found[$symbol] = ['confidence' => 0.5, 'method' => 'symbol_ctx'];
            }
        }

        return $found;
    }

    /**
     * True when the words around the candidate are themselves mostly
     * ALL-CAPS — capitalization carries no ticker signal inside shouting.
     */
    protected function isShoutingContext(string $text, int $offset, string $symbol): bool
    {
        $window = substr($text, max(0, $offset - 60), 60 + strlen($symbol) + 60);

        preg_match_all('/[A-Za-z]{2,}/', $window, $words);

        $neighbors = array_values(array_filter($words[0], fn (string $w): bool => strtoupper($w) !== $symbol));

        if (count($neighbors) < 2) {
            return false;
        }

        $caps = count(array_filter($neighbors, fn (string $w): bool => $w === strtoupper($w)));

        return $caps / count($neighbors) >= 0.6;
    }

    /**
     * Position-aware cue rescue — the only way an English-word symbol
     * counts bare: a noun cue immediately after it ("HIT shares") or a
     * trading verb within the two words before it ("bought more HIT").
     */
    protected function hasAdjacentFinanceCue(string $text, int $offset, string $symbol): bool
    {
        $before = substr($text, max(0, $offset - 40), min($offset, 40));
        $after = substr($text, $offset + strlen($symbol), 40);

        preg_match_all('/[A-Za-z]+/', $before, $b);
        preg_match_all('/[A-Za-z]+/', $after, $a);

        $next = strtolower($a[0][0] ?? '');

        if (in_array($next, self::CUES_AFTER, true)) {
            return true;
        }

        foreach (array_slice($b[0], -2) as $word) {
            if (in_array(strtolower($word), self::CUES_BEFORE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Catches UPPERCASE censored profanity ("STUPID $HIT IS THIS"): the tag
     * spells profanity with an S prefix AND the word right before it is a
     * determiner/possessive/adjective. Applies only to the profanity list,
     * so ordinary cashtags never trip it.
     */
    protected function isCensoredProfanity(string $text, int $offset, string $raw): bool
    {
        if (! in_array('S'.strtoupper($raw), self::DOLLAR_AS_S_WORDS, true)) {
            return false;
        }

        preg_match_all('/[A-Za-z]+/', substr($text, max(0, $offset - 30), min($offset, 30)), $b);

        $preceding = strtolower($b[0][count($b[0]) - 1] ?? '');

        return in_array($preceding, self::PROFANITY_PRECEDERS, true);
    }

    /**
     * "$hit" is "shit", not a $HIT cashtag: when the tag is written fully
     * lowercase and prefixing an S yields an English word, the dollar sign
     * is censorship/stylization, not a ticker. Uppercase tags ($HIT) are
     * unaffected — deliberate cashtags survive.
     */
    protected function isDollarAsS(string $raw): bool
    {
        if ($raw !== strtolower($raw)) {
            return false;
        }

        $sWord = 'S'.strtoupper($raw);

        return in_array($sWord, self::DOLLAR_AS_S_WORDS, true) || $this->isEnglishWord($sWord);
    }

    protected function isKnownSymbol(string $symbol): bool
    {
        return in_array($symbol, $this->activeSymbols(), true);
    }

    protected function isAmbiguous(string $symbol): bool
    {
        return in_array($symbol, config('pennyhunt.ambiguous_symbols'), true);
    }

    protected function isEnglishWord(string $symbol): bool
    {
        return isset($this->englishWords()[$symbol]);
    }

    /**
     * Top-10k English words (2-5 letters, uppercased) — symbols colliding
     * with these need contextual rescue before a bare match counts.
     *
     * @return array<string, true>
     */
    protected function englishWords(): array
    {
        static $words = null;

        return $words ??= array_fill_keys(
            array_filter(array_map('trim', file(resource_path('data/common-english-words.txt')) ?: [])),
            true,
        );
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
