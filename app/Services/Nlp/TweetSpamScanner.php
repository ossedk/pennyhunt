<?php

namespace App\Services\Nlp;

/**
 * Fast, free heuristic filter for cashtag-feed junk BEFORE it hits the NLP
 * pipeline. Twitter cashtags are polluted by two failure modes Reddit
 * doesn't have at this scale:
 *
 *  1. Crypto symbol collisions — a token/airdrop shares the $symbol of the
 *     stock we track ("$GME airdrop live, connect wallet"). These would count
 *     as mentions and poison mention velocity.
 *  2. Bot/engagement-farm posts — cashtag-stuffed spam tagging 8 tickers to
 *     surf every feed at once.
 *
 * The scanner is deliberately conservative: crypto vocabulary only flags a
 * tweet when NO equity context is present, so "shorts covering, squeeze on,
 * also holding some BTC" survives. Subtle cases are left to the LLM
 * classifier's off_topic verdict (second layer).
 */
class TweetSpamScanner
{
    /** Strong crypto/web3 markers (checked as whole words, lowercase). */
    protected const CRYPTO_TERMS = [
        'airdrop', 'presale', 'pre-sale', 'whitelist', 'mint', 'minting',
        'staking', 'stake now', 'token', 'tokenomics', 'token launch',
        'memecoin', 'meme coin', 'web3', 'defi', 'nft', 'nfts',
        'smart contract', 'connect wallet', 'connect your wallet',
        'claim now', 'claim your', 'dex listing', 'cex listing',
        'liquidity pool', 'rug', 'rugpull', 'pump group', 'binance listing',
        'coinbase listing', 'solana gem', 'bsc gem', '1000x gem',
        'to the blockchain', 'pumpfun', 'pump fun', 'holder count', 'on-chain',
        'onchain',
    ];

    /**
     * Equity context that rescues a tweet from the crypto verdict.
     * Deliberately NOT included: "ticker", "pump", "moon", "ATH" — crypto
     * promoters use those constantly ("the ticker $WEN"), so they rescue
     * nothing.
     */
    protected const STOCK_TERMS = [
        'stock', 'stocks', 'shares', 'share price', 'float', 'nasdaq', 'nyse',
        'otc', 'earnings', 'eps', 'revenue', 'sec filing', '10-k', '10-q',
        '8-k', 's-3', 'fda', 'short interest', 'shorts', 'squeeze',
        'premarket', 'pre-market', 'after hours', 'halted', 'dilution',
        'offering', 'market cap', 'ipo', 'calls', 'puts', 'options',
        'dividend', 'dividends', 'guidance',
    ];

    /**
     * Unambiguous crypto symbols. When a tweet's cashtags include 2+ of
     * these and no equity context, it's a crypto thread — even if one of
     * the cashtags collides with a stock we track ("$SOL to 500, $WEN to
     * 0.01" is about the WEN memecoin, not Wendy's). Deliberately excludes
     * symbols that are also liquid US tickers (LTC, ETC, APT, W, TRX…).
     */
    protected const CRYPTO_SYMBOLS = [
        'BTC', 'ETH', 'SOL', 'XRP', 'DOGE', 'ADA', 'BNB', 'USDT', 'USDC',
        'SHIB', 'PEPE', 'AVAX', 'XLM', 'XMR', 'JUP', 'WIF', 'BONK', 'FLOKI',
        'HBAR', 'KAS', 'INJ', 'MATIC', 'DOT', 'ATOM', 'SUI', 'TIA', 'SEI',
        'RNDR', 'FET', 'ONDO', 'PYTH', 'JTO', 'STRK', 'MEME', 'ENA', 'WLD',
    ];

    /** Cashtags beyond this count = feed-surfing spam, whatever the topic. */
    protected const MAX_CASHTAGS = 6;

    /** Returns a spam reason, or null when the tweet looks legitimate. */
    public function scan(string $text): ?string
    {
        $lower = mb_strtolower($text);

        if ($this->cashtagCount($text) > self::MAX_CASHTAGS) {
            return 'cashtag_stuffing';
        }

        // Contract addresses are a hard crypto tell: Ethereum (0x + 40 hex)
        // or Solana/pump.fun (base58, 32-44 chars — e.g. "AnSem47...pump").
        // No English word or ticker is 32+ chars, so false positives are nil.
        if (preg_match('/\b0x[a-f0-9]{40}\b/', $lower) === 1
            || preg_match('/\b[1-9A-HJ-NP-Za-km-z]{32,44}\b/', $text) === 1) {
            return 'crypto_contract_address';
        }

        if ($this->containsAny($lower, self::CRYPTO_TERMS) && ! $this->containsAny($lower, self::STOCK_TERMS)) {
            return 'crypto_offtopic';
        }

        // 2+ recognised crypto cashtags with no equity context = crypto
        // thread; any colliding stock cashtag riding along is coincidence.
        if ($this->cryptoCashtagCount($text) >= 2 && ! $this->containsAny($lower, self::STOCK_TERMS)) {
            return 'crypto_cashtags';
        }

        // Telegram invite links on a cashtag tweet are promo-group funnels.
        if (str_contains($lower, 't.me/') && ! $this->containsAny($lower, self::STOCK_TERMS)) {
            return 'promo_funnel_link';
        }

        return null;
    }

    protected function cashtagCount(string $text): int
    {
        return preg_match_all('/\$[A-Za-z]{1,6}\b/', $text) ?: 0;
    }

    protected function cryptoCashtagCount(string $text): int
    {
        preg_match_all('/\$([A-Za-z]{1,6})\b/', $text, $matches);

        $symbols = array_unique(array_map(strtoupper(...), $matches[1]));

        return count(array_intersect($symbols, self::CRYPTO_SYMBOLS));
    }

    /**
     * Word-boundary matching — substring matching would flag "drug" for
     * "rug" or "mints" inside unrelated words, and biotech penny stocks talk
     * about drugs constantly.
     *
     * @param  array<int, string>  $needles
     */
    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
