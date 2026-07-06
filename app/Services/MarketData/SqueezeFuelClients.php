<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Free squeeze-fuel data sources, one thin client per feed:
 *
 *  - FINRA Query API (keyless for public datasets): bi-monthly equity short
 *    interest. POST https://api.finra.org/data/group/otcMarket/name/EquityShortInterest
 *    filtered by settlementDate, CSV out.
 *  - SEC CNS fails-to-deliver: bi-monthly zips at
 *    sec.gov/files/data/fails-deliver-data/cnsfails{YYYYMM}{a|b}.zip,
 *    pipe-delimited SETTLEMENT DATE|CUSIP|SYMBOL|QUANTITY|DESCRIPTION|PRICE.
 *  - iBorrowDesk (IBKR mirror, unofficial): GET /api/ticker/{symbol} JSON
 *    with daily borrow fee + available-shares history.
 *  - NASDAQ Trader trade-halts RSS (all US venues, incl. LULD).
 */
class SqueezeFuelClients
{
    /**
     * Short interest rows for a settlement-date window (FINRA's settlement
     * dates follow their bi-monthly schedule and shift around holidays —
     * a range query catches them without knowing the calendar).
     *
     * @return array<int, array{symbol: string, settlement_date: string, shares_short: int, days_to_cover: ?float}>
     */
    public function finraShortInterest(string $from, string $to): array
    {
        $rows = [];
        $offset = 0;
        $batch = [];

        do {
            $response = Http::timeout(120)
                ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                ->post('https://api.finra.org/data/group/otcMarket/name/EquityShortInterest', [
                    'compareFilters' => [
                        ['compareType' => 'GTE', 'fieldName' => 'settlementDate', 'fieldValue' => $from],
                        ['compareType' => 'LTE', 'fieldName' => 'settlementDate', 'fieldValue' => $to],
                    ],
                    'limit' => 5000,
                    'offset' => $offset,
                ]);

            if (! $response->successful()) {
                break;
            }

            $batch = $response->json() ?? [];

            foreach ($batch as $row) {
                $symbol = strtoupper(trim((string) ($row['symbolCode'] ?? '')));
                $shares = (int) ($row['currentShortPositionQuantity'] ?? 0);
                $settlement = (string) ($row['settlementDate'] ?? '');

                if ($symbol === '' || $shares <= 0 || $settlement === '') {
                    continue;
                }

                $rows[] = [
                    'symbol' => $symbol,
                    'settlement_date' => substr($settlement, 0, 10),
                    'shares_short' => $shares,
                    'days_to_cover' => isset($row['daysToCoverQuantity']) && is_numeric($row['daysToCoverQuantity'])
                        ? (float) $row['daysToCoverQuantity']
                        : null,
                ];
            }

            $offset += 5000;
        } while (count($batch) === 5000 && $offset < 500_000);

        return $rows;
    }

    /**
     * FTD rows from one half-month SEC zip ('202606a' style period).
     *
     * @return array<int, array{settlement_date: string, symbol: string, fails: int, price: ?float}>
     */
    public function secFailsToDeliver(string $period): array
    {
        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => config('pennyhunt.sec_user_agent')])
            ->get("https://www.sec.gov/files/data/fails-deliver-data/cnsfails{$period}.zip");

        if (! $response->successful()) {
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ftd');
        file_put_contents($tmp, $response->body());

        $zip = new \ZipArchive;

        if ($zip->open($tmp) !== true) {
            unlink($tmp);

            return [];
        }

        $text = $zip->getFromIndex(0) ?: '';
        $zip->close();
        unlink($tmp);

        $rows = [];

        foreach (explode("\n", $text) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue; // header
            }

            $parts = explode('|', trim($line));

            if (count($parts) < 6 || ! preg_match('/^\d{8}$/', $parts[0])) {
                continue; // trailer rows
            }

            $rows[] = [
                'settlement_date' => substr($parts[0], 0, 4).'-'.substr($parts[0], 4, 2).'-'.substr($parts[0], 6, 2),
                'symbol' => strtoupper(trim($parts[2])),
                'fails' => (int) $parts[3],
                'price' => is_numeric($parts[5]) ? (float) $parts[5] : null,
            ];
        }

        return $rows;
    }

    /**
     * Borrow fee/availability history for one symbol (daily granularity).
     *
     * @return array<int, array{day: string, fee: ?float, available: ?int}>
     */
    public function iborrowDesk(string $symbol): array
    {
        $response = Http::timeout(30)
            ->retry(2, 2000, throw: false)
            ->get('https://iborrowdesk.com/api/ticker/'.rawurlencode(strtoupper($symbol)));

        if (! $response->successful()) {
            return [];
        }

        $out = [];

        foreach ($response->json('daily') ?? [] as $row) {
            $time = (string) ($row['time'] ?? '');

            if ($time === '') {
                continue;
            }

            $out[] = [
                'day' => substr($time, 0, 10),
                'fee' => isset($row['fee']) && is_numeric($row['fee']) ? (float) $row['fee'] : null,
                'available' => isset($row['available']) && is_numeric($row['available']) ? (int) $row['available'] : null,
            ];
        }

        return $out;
    }

    /**
     * Current/recent trade halts from the NASDAQ Trader RSS feed.
     *
     * @return array<int, array{symbol: string, halted_at: string, resumed_at: ?string, reason: ?string}>
     */
    public function nasdaqHalts(): array
    {
        $response = Http::timeout(30)->get('https://www.nasdaqtrader.com/rss.aspx', ['feed' => 'tradehalts']);

        if (! $response->successful()) {
            return [];
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            return [];
        }

        $out = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $ndaq = $item->children('http://www.nasdaqtrader.com/');
            $symbol = strtoupper(trim((string) ($ndaq->IssueSymbol ?? '')));

            if ($symbol === '') {
                continue;
            }

            $haltDate = trim((string) ($ndaq->HaltDate ?? ''));
            $haltTime = trim((string) ($ndaq->HaltTime ?? ''));
            $resDate = trim((string) ($ndaq->ResumptionDate ?? ''));
            $resTime = trim((string) ($ndaq->ResumptionTradeTime ?? ''));

            if ($haltDate === '') {
                continue;
            }

            $toIso = function (string $date, string $time): ?string {
                if ($date === '') {
                    return null;
                }

                $ts = strtotime(trim($date.' '.($time ?: '00:00:00')).' America/New_York');

                return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
            };

            $haltedAt = $toIso($haltDate, $haltTime);

            if ($haltedAt === null) {
                continue;
            }

            $out[] = [
                'symbol' => $symbol,
                'halted_at' => $haltedAt,
                'resumed_at' => $toIso($resDate, $resTime),
                'reason' => trim((string) ($ndaq->ReasonCode ?? '')) ?: null,
            ];
        }

        return $out;
    }
}
