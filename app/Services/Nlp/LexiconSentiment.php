<?php

namespace App\Services\Nlp;

/**
 * Tier-0 sentiment: fast lexicon scorer with a WSB/pennystock-aware vocabulary.
 *
 * This is intentionally simple. It exists as a full-coverage baseline signal
 * that the backtest engine compares against FinBERT (tier 1) and LLM (tier 2)
 * scores; it is NOT expected to be the best signal.
 *
 * Returns a score in [-1, 1].
 */
class LexiconSentiment
{
    /** @var array<string, float> */
    protected const LEXICON = [
        // Bullish - general
        'buy' => 0.5, 'buying' => 0.5, 'bought' => 0.4, 'long' => 0.4, 'bull' => 0.6, 'bullish' => 0.8,
        'calls' => 0.5, 'call' => 0.3, 'up' => 0.2, 'upside' => 0.5, 'gain' => 0.4, 'gains' => 0.5,
        'profit' => 0.4, 'winner' => 0.5, 'winning' => 0.5, 'growth' => 0.3, 'breakout' => 0.7,
        'undervalued' => 0.7, 'oversold' => 0.5, 'catalyst' => 0.5, 'beat' => 0.4, 'beats' => 0.5,
        'upgrade' => 0.5, 'upgraded' => 0.5, 'surge' => 0.6, 'surging' => 0.7, 'soar' => 0.7,
        'soaring' => 0.7, 'rally' => 0.5, 'rip' => 0.4, 'ripping' => 0.6, 'running' => 0.3,
        'accumulate' => 0.5, 'accumulating' => 0.5, 'load' => 0.3, 'loading' => 0.4,
        // Bullish - WSB dialect
        'moon' => 0.8, 'mooning' => 0.9, 'rocket' => 0.7, 'tendies' => 0.6, 'squeeze' => 0.6,
        'yolo' => 0.5, 'diamond' => 0.4, 'hands' => 0.0, 'hodl' => 0.5, 'hold' => 0.3,
        'holding' => 0.3, 'apes' => 0.3, 'lfg' => 0.7, 'printing' => 0.5, 'brrr' => 0.5,
        'gamma' => 0.2, 'undervalue' => 0.6, 'stonks' => 0.4, 'lambo' => 0.6,
        // Bearish - general
        'sell' => -0.5, 'selling' => -0.5, 'sold' => -0.4, 'short' => -0.4, 'bear' => -0.6,
        'bearish' => -0.8, 'puts' => -0.5, 'put' => -0.2, 'down' => -0.2, 'downside' => -0.5,
        'loss' => -0.4, 'losses' => -0.5, 'loser' => -0.5, 'crash' => -0.7, 'crashing' => -0.8,
        'dump' => -0.6, 'dumping' => -0.7, 'tank' => -0.6, 'tanking' => -0.7, 'plunge' => -0.7,
        'overvalued' => -0.7, 'overbought' => -0.5, 'miss' => -0.4, 'missed' => -0.4,
        'downgrade' => -0.5, 'downgraded' => -0.5, 'dilution' => -0.7, 'offering' => -0.4,
        'bankruptcy' => -0.9, 'bankrupt' => -0.9, 'delisting' => -0.8, 'delisted' => -0.8,
        'fraud' => -0.8, 'scam' => -0.9, 'investigation' => -0.5, 'lawsuit' => -0.4,
        'halted' => -0.5, 'halt' => -0.3, 'weak' => -0.3, 'avoid' => -0.6, 'warning' => -0.4,
        // Bearish - WSB dialect
        'bagholder' => -0.7, 'bagholding' => -0.7, 'bags' => -0.5, 'rug' => -0.8, 'rugpull' => -0.9,
        'drilling' => -0.6, 'guh' => -0.6, 'rekt' => -0.7, 'paper' => -0.2, 'shill' => -0.5,
        'pumped' => -0.3, 'dumped' => -0.6,
    ];

    /** @var array<string, float> emoji signals, weighted strongly (deliberate on forums) */
    protected const EMOJI = [
        '🚀' => 0.8, '🌙' => 0.6, '💎' => 0.5, '🙌' => 0.3, '📈' => 0.6, '🐂' => 0.6, '💰' => 0.4,
        '🔥' => 0.4, '📉' => -0.6, '🐻' => -0.6, '💩' => -0.5, '🩸' => -0.6, '⚰️' => -0.7, '🤡' => -0.4,
    ];

    protected const NEGATORS = ['not', 'no', 'never', "don't", 'dont', "isn't", 'isnt', "won't", 'wont', "can't", 'cant', 'without'];

    public function score(string $text): float
    {
        $text = mb_strtolower($text);

        $total = 0.0;
        $hits = 0;

        // Emoji pass (before tokenization strips them)
        foreach (self::EMOJI as $emoji => $weight) {
            $count = mb_substr_count($text, $emoji);
            if ($count > 0) {
                // Cap repeated-emoji spam contribution
                $total += $weight * min($count, 3);
                $hits += min($count, 3);
            }
        }

        $tokens = preg_split('/[^a-z\']+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $i => $token) {
            $weight = self::LEXICON[$token] ?? null;
            if ($weight === null) {
                continue;
            }

            // Simple negation flip when a negator appears within 2 tokens before
            for ($j = max(0, $i - 2); $j < $i; $j++) {
                if (in_array($tokens[$j], self::NEGATORS, true)) {
                    $weight = -$weight;
                    break;
                }
            }

            $total += $weight;
            $hits++;
        }

        if ($hits === 0) {
            return 0.0;
        }

        // Normalize: average weight, squashed into [-1, 1]
        return round(max(-1.0, min(1.0, $total / max($hits, 3))), 4);
    }
}
