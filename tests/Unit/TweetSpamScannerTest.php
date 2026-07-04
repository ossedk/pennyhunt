<?php

use App\Services\Nlp\TweetSpamScanner;

beforeEach(function () {
    $this->scanner = new TweetSpamScanner;
});

it('flags crypto airdrop promos colliding with a stock cashtag', function () {
    expect($this->scanner->scan('$GME airdrop is LIVE! Connect wallet and claim your tokens before the presale ends'))
        ->toBe('crypto_offtopic');
});

it('flags contract-address spam', function () {
    expect($this->scanner->scan('New gem $ABCD 0x1234567890abcdef1234567890abcdef12345678 100x soon'))
        ->toBe('crypto_contract_address');
});

it('flags cashtag stuffing', function () {
    expect($this->scanner->scan('Watch $AAA $BBB $CCC $DDD $EEE $FFF $GGG today — big moves'))
        ->toBe('cashtag_stuffing');
});

it('flags telegram funnel links without stock context', function () {
    expect($this->scanner->scan('$XYZ signal group is printing, join t.me/pumpsignals now'))
        ->toBe('promo_funnel_link');
});

it('flags crypto threads by recognised crypto cashtags even when a stock symbol collides', function () {
    expect($this->scanner->scan('$SOL to 500$ $JUP to 5$ $WEN to 0.01$ $BTC to 150k$ It\'s question of WEN, not if.'))
        ->toBe('crypto_cashtags');
});

it('keeps a stock tweet that references bitcoin once with equity context', function () {
    expect($this->scanner->scan('$MARA earnings tonight — miners follow $BTC but the stock has its own float story'))
        ->toBeNull();
});

it('keeps genuine stock tweets that mention crypto vocabulary with equity context', function () {
    expect($this->scanner->scan('$ABCD short interest is wild, shorts trapped — this squeeze could mint new millionaires'))
        ->toBeNull();
});

it('keeps plain bullish stock tweets', function () {
    expect($this->scanner->scan('$ABCD earnings beat, revenue up 40% YoY. Float is tiny, NASDAQ listed. Loading calls'))
        ->toBeNull();
});
