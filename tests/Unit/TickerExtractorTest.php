<?php

namespace Tests\Unit;

use App\Models\Ticker;
use App\Services\Nlp\TickerExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TickerExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Ticker::create(['symbol' => 'GME', 'name' => 'GameStop', 'is_active' => true]);
        Ticker::create(['symbol' => 'ABCD', 'name' => 'Test Corp', 'is_active' => true]);
        Ticker::create(['symbol' => 'CEO', 'name' => 'Ambiguous Inc', 'is_active' => true, 'is_ambiguous' => true]);
        Ticker::create(['symbol' => 'DEAD', 'name' => 'Delisted Co', 'is_active' => false]);
        Ticker::create(['symbol' => 'HIT', 'name' => 'Health In Tech', 'is_active' => true]);
        Ticker::create(['symbol' => 'NOW', 'name' => 'ServiceNow', 'is_active' => true]);
    }

    public function test_cashtags_are_extracted_with_full_confidence(): void
    {
        $result = app(TickerExtractor::class)->extract('Loading up on $GME before earnings');

        $this->assertArrayHasKey('GME', $result);
        $this->assertSame(1.0, $result['GME']['confidence']);
        $this->assertSame('cashtag', $result['GME']['method']);
    }

    public function test_bare_valid_symbols_are_extracted_with_lower_confidence(): void
    {
        $result = app(TickerExtractor::class)->extract('ABCD is going to run tomorrow');

        $this->assertArrayHasKey('ABCD', $result);
        $this->assertSame(0.7, $result['ABCD']['confidence']);
    }

    public function test_ambiguous_symbols_require_cashtag(): void
    {
        $extractor = app(TickerExtractor::class);

        // Bare "CEO" is a common word — must be ignored
        $this->assertArrayNotHasKey('CEO', $extractor->extract('The CEO said nothing new'));

        // But $CEO is explicit
        $this->assertArrayHasKey('CEO', $extractor->extract('Buying $CEO calls'));
    }

    public function test_unknown_and_inactive_symbols_are_ignored(): void
    {
        $result = app(TickerExtractor::class)->extract('$ZZZZZ and DEAD are not tradable');

        $this->assertSame([], $result);
    }

    public function test_lowercase_words_are_not_matched(): void
    {
        $result = app(TickerExtractor::class)->extract('gme to the moon');

        $this->assertArrayNotHasKey('GME', $result);
    }

    public function test_english_word_symbols_need_a_finance_cue(): void
    {
        $extractor = app(TickerExtractor::class);

        // "HIT" as a plain verb — never a mention.
        $this->assertArrayNotHasKey('HIT', $extractor->extract('This news really HIT hard today'));

        // Adjacent finance cue rescues it, at reduced confidence.
        $rescued = $extractor->extract('Bought more HIT shares this morning');
        $this->assertArrayHasKey('HIT', $rescued);
        $this->assertSame(0.5, $rescued['HIT']['confidence']);
        $this->assertSame('symbol_ctx', $rescued['HIT']['method']);

        // Explicit cashtag always counts at full confidence.
        $this->assertSame(1.0, $extractor->extract('$HIT to the moon')['HIT']['confidence']);
    }

    public function test_all_caps_shouting_never_produces_bare_matches(): void
    {
        // The real-world failure: a $NOW tweet shouting "HIT THE BOTTOM".
        $result = app(TickerExtractor::class)
            ->extract("\$NOW HIT THE BOTTOM AT \$89 I'm calling \$200 EOY. Save this. Thank me later");

        $this->assertArrayHasKey('NOW', $result); // explicit cashtag
        $this->assertSame('cashtag', $result['NOW']['method']);
        $this->assertArrayNotHasKey('HIT', $result); // shouted word, not a ticker
    }

    public function test_tweets_only_count_cashtags(): void
    {
        $result = app(TickerExtractor::class)
            ->extract('Huge ABCD volume today, also watching $GME', 'twitter');

        $this->assertArrayHasKey('GME', $result);
        $this->assertArrayNotHasKey('ABCD', $result);
    }

    public function test_censored_profanity_is_not_a_cashtag(): void
    {
        $extractor = app(TickerExtractor::class);

        // "$hit" = censored "shit", not the HIT ticker.
        $this->assertArrayNotHasKey('HIT', $extractor->extract('Rep. Crock of $hit!! We will disrespect you'));

        // Deliberate uppercase cashtag still counts.
        $this->assertArrayHasKey('HIT', $extractor->extract('Loading $HIT before earnings'));
    }
}
