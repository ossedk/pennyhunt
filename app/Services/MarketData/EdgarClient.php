<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * SEC EDGAR (free, keyless). Fair-access policy: identifying User-Agent and
 * max 10 req/s — we throttle to ~8 req/s across calls.
 *
 * Two endpoints power the dilution features:
 *  - data.sec.gov/submissions/CIK##########.json — the company's filing
 *    index (form + filing date + accession). "recent" covers >= 1 year or
 *    1000 filings, which for small caps typically spans many years.
 *  - data.sec.gov/api/xbrl/companyconcept/.../dei/EntityCommonStockSharesOutstanding.json
 *    — cover-page shares outstanding per report, i.e. a point-in-time share
 *    count series for realized-dilution measurement.
 */
class EdgarClient
{
    /**
     * Full submissions index: SIC industry code + the recent filing list
     * (form, date, accession, primary document). One request serves the
     * dilution sync, the SIC backfill AND the Form 4 index.
     *
     * @return array{sic: ?string, filings: array<int, array{form: string, filed_at: string, accession: string, primary_document: ?string}>}
     */
    public function submissions(int $cik): array
    {
        $json = $this->get(sprintf('https://data.sec.gov/submissions/CIK%010d.json', $cik))?->json();

        $recent = $json['filings']['recent'] ?? null;
        $filings = [];

        if (is_array($recent) && isset($recent['form'], $recent['filingDate'], $recent['accessionNumber'])) {
            foreach ($recent['form'] as $i => $form) {
                $filings[] = [
                    'form' => (string) $form,
                    'filed_at' => (string) $recent['filingDate'][$i],
                    'accession' => (string) $recent['accessionNumber'][$i],
                    'primary_document' => isset($recent['primaryDocument'][$i]) ? (string) $recent['primaryDocument'][$i] : null,
                ];
            }
        }

        return [
            'sic' => isset($json['sic']) && $json['sic'] !== '' ? (string) $json['sic'] : null,
            'filings' => $filings,
        ];
    }

    /**
     * @return array<int, array{form: string, filed_at: string, accession: string, primary_document: ?string}>
     */
    public function filings(int $cik): array
    {
        return $this->submissions($cik)['filings'];
    }

    /**
     * Open-market insider transactions from a Form 4 filing (transaction
     * codes P = purchase, S = sale; derivative legs and grants excluded).
     *
     * @return array<int, array{seq: int, transacted_at: ?string, owner_name: ?string, is_officer: bool, is_director: bool, code: string, shares: ?float, price: ?float}>
     */
    public function form4Transactions(int $cik, string $accession, string $primaryDocument): array
    {
        $url = sprintf(
            'https://www.sec.gov/Archives/edgar/data/%d/%s/%s',
            $cik,
            str_replace('-', '', $accession),
            $primaryDocument,
        );

        $body = $this->get($url)?->body();

        if ($body === null || trim($body) === '') {
            return [];
        }

        $xml = @simplexml_load_string($body);

        if ($xml === false) {
            return [];
        }

        $owner = $xml->reportingOwner ?? null;
        $ownerName = $owner !== null ? trim((string) ($owner->reportingOwnerId->rptOwnerName ?? '')) : '';
        $isOfficer = $owner !== null && trim((string) ($owner->reportingOwnerRelationship->isOfficer ?? '')) === '1';
        $isDirector = $owner !== null && trim((string) ($owner->reportingOwnerRelationship->isDirector ?? '')) === '1';

        $out = [];
        $seq = 0;

        foreach ($xml->nonDerivativeTable->nonDerivativeTransaction ?? [] as $txn) {
            $code = strtoupper(trim((string) ($txn->transactionCoding->transactionCode ?? '')));
            $seq++;

            if (! in_array($code, ['P', 'S'], true)) {
                continue;
            }

            $shares = (string) ($txn->transactionAmounts->transactionShares->value ?? '');
            $price = (string) ($txn->transactionAmounts->transactionPricePerShare->value ?? '');

            $out[] = [
                'seq' => $seq,
                'transacted_at' => trim((string) ($txn->transactionDate->value ?? '')) ?: null,
                'owner_name' => $ownerName !== '' ? $ownerName : null,
                'is_officer' => $isOfficer,
                'is_director' => $isDirector,
                'code' => $code,
                'shares' => is_numeric($shares) ? (float) $shares : null,
                'price' => is_numeric($price) ? (float) $price : null,
            ];
        }

        return $out;
    }

    /**
     * Shares-outstanding observations, keyed by as-of date (deduplicated,
     * newest filing wins per date).
     *
     * @return array<string, int>
     */
    public function sharesOutstanding(int $cik): array
    {
        $json = $this->get(sprintf(
            'https://data.sec.gov/api/xbrl/companyconcept/CIK%010d/dei/EntityCommonStockSharesOutstanding.json',
            $cik,
        ))?->json();

        $out = [];

        foreach ($json['units']['shares'] ?? [] as $fact) {
            $date = $fact['end'] ?? null;
            $value = $fact['val'] ?? null;

            if ($date !== null && is_numeric($value) && (float) $value > 0) {
                $out[$date] = (int) $value;
            }
        }

        ksort($out);

        return $out;
    }

    protected function get(string $url): ?Response
    {
        Sleep::for(125)->milliseconds(); // ~8 req/s, under SEC's 10 req/s cap

        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => config('pennyhunt.sec_user_agent')])
            ->get($url);

        // 404 = company has no facts / unknown CIK — a normal outcome.
        return $response->successful() ? $response : null;
    }
}
