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
     * @return array<int, array{form: string, filed_at: string, accession: string}>
     */
    public function filings(int $cik): array
    {
        $json = $this->get(sprintf('https://data.sec.gov/submissions/CIK%010d.json', $cik))?->json();

        $recent = $json['filings']['recent'] ?? null;

        if (! is_array($recent) || ! isset($recent['form'], $recent['filingDate'], $recent['accessionNumber'])) {
            return [];
        }

        $out = [];

        foreach ($recent['form'] as $i => $form) {
            $out[] = [
                'form' => (string) $form,
                'filed_at' => (string) $recent['filingDate'][$i],
                'accession' => (string) $recent['accessionNumber'][$i],
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
