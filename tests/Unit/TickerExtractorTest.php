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
}
