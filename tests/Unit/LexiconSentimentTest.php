<?php

namespace Tests\Unit;

use App\Services\Nlp\LexiconSentiment;
use PHPUnit\Framework\TestCase;

class LexiconSentimentTest extends TestCase
{
    public function test_bullish_wsb_slang_scores_positive(): void
    {
        $score = (new LexiconSentiment)->score('YOLO calls, this is mooning 🚀🚀 diamond hands');

        $this->assertGreaterThan(0, $score);
    }

    public function test_bearish_text_scores_negative(): void
    {
        $score = (new LexiconSentiment)->score('Total scam, dilution incoming, bagholders everywhere, avoid');

        $this->assertLessThan(0, $score);
    }

    public function test_neutral_text_scores_zero(): void
    {
        $score = (new LexiconSentiment)->score('The quarterly report will be published on Tuesday.');

        $this->assertSame(0.0, $score);
    }

    public function test_negation_flips_polarity(): void
    {
        $positive = (new LexiconSentiment)->score('bullish');
        $negated = (new LexiconSentiment)->score("not bullish");

        $this->assertGreaterThan(0, $positive);
        $this->assertLessThan(0, $negated);
    }

    public function test_score_is_bounded(): void
    {
        $score = (new LexiconSentiment)->score(str_repeat('moon rocket squeeze tendies ', 50));

        $this->assertLessThanOrEqual(1.0, $score);
        $this->assertGreaterThanOrEqual(-1.0, $score);
    }
}
