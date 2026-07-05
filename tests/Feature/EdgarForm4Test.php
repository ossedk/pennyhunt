<?php

use App\Services\MarketData\EdgarClient;
use Illuminate\Support\Facades\Http;

const FORM4_XML = <<<'XML'
<?xml version="1.0"?>
<ownershipDocument>
    <reportingOwner>
        <reportingOwnerId>
            <rptOwnerCik>0001234567</rptOwnerCik>
            <rptOwnerName>DOE JANE</rptOwnerName>
        </reportingOwnerId>
        <reportingOwnerRelationship>
            <isDirector>1</isDirector>
            <isOfficer>0</isOfficer>
        </reportingOwnerRelationship>
    </reportingOwner>
    <nonDerivativeTable>
        <nonDerivativeTransaction>
            <transactionDate><value>2026-06-15</value></transactionDate>
            <transactionCoding><transactionCode>P</transactionCode></transactionCoding>
            <transactionAmounts>
                <transactionShares><value>50000</value></transactionShares>
                <transactionPricePerShare><value>1.25</value></transactionPricePerShare>
            </transactionAmounts>
        </nonDerivativeTransaction>
        <nonDerivativeTransaction>
            <transactionDate><value>2026-06-16</value></transactionDate>
            <transactionCoding><transactionCode>M</transactionCode></transactionCoding>
            <transactionAmounts>
                <transactionShares><value>1000</value></transactionShares>
                <transactionPricePerShare><value>0.50</value></transactionPricePerShare>
            </transactionAmounts>
        </nonDerivativeTransaction>
    </nonDerivativeTable>
</ownershipDocument>
XML;

it('parses open-market transactions and strips the xsl view prefix from the document path', function () {
    Http::fake(function ($request) {
        // The index lists "xslF345X06/foo.xml"; the client must request the
        // RAW xml (no xsl prefix) — the HTML view parses to nothing.
        expect($request->url())->toBe('https://www.sec.gov/Archives/edgar/data/2488/000000248826000113/wk-form4.xml');

        return Http::response(FORM4_XML);
    });

    $txns = app(EdgarClient::class)->form4Transactions(2488, '0000002488-26-000113', 'xslF345X06/wk-form4.xml');

    // Only the P transaction survives (M = option exercise, skipped).
    expect($txns)->toHaveCount(1)
        ->and($txns[0]['code'])->toBe('P')
        ->and($txns[0]['owner_name'])->toBe('DOE JANE')
        ->and($txns[0]['is_director'])->toBeTrue()
        ->and($txns[0]['is_officer'])->toBeFalse()
        ->and($txns[0]['shares'])->toEqual(50000.0)
        ->and($txns[0]['price'])->toEqual(1.25);
});

it('returns empty for html bodies instead of spraying parse errors', function () {
    Http::fake(['sec.gov/*' => Http::response('<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01"><html><body>SEC Form 4</body></html>')]);

    expect(app(EdgarClient::class)->form4Transactions(2488, '0000002488-26-000113', 'wk-form4.xml'))->toBe([]);
});
