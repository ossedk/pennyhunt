<?php

namespace App\Http\Controllers;

use App\Jobs\Ingestion\PullTwitterForTicker;
use App\Models\Ticker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Global ticker search: exact symbol first, then symbol prefix, then
 * company-name substring — ranked inside each tier by 24h social
 * attention so the name everyone is talking about outranks lookalikes.
 * An exact-symbol hit warms the ticker's X/Twitter tape in the
 * background (intent signal, cooldown-guarded).
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 1) {
            return response()->json(['results' => []]);
        }

        $symbol = strtoupper($q);
        $like = str_replace(['%', '_'], ['\%', '\_'], $q);

        $results = Ticker::query()
            ->where('is_active', true)
            ->where(function ($query) use ($symbol, $like): void {
                $query->where('symbol', $symbol)
                    ->orWhere('symbol', 'like', $symbol.'%')
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($like).'%']);
            })
            ->leftJoinSub(
                DB::table('post_ticker_mentions')
                    ->selectRaw('ticker_id, COUNT(*) AS mentions_24h')
                    ->where('posted_at', '>=', now()->subHours(24))
                    ->groupBy('ticker_id'),
                'buzz',
                'buzz.ticker_id',
                'tickers.id',
            )
            ->orderByRaw('CASE WHEN symbol = ? THEN 0 WHEN symbol LIKE ? THEN 1 ELSE 2 END', [$symbol, $symbol.'%'])
            ->orderByRaw('COALESCE(buzz.mentions_24h, 0) DESC')
            ->orderByRaw('COALESCE(market_cap, 0) DESC')
            ->limit(8)
            ->get(['tickers.id', 'symbol', 'name', 'exchange', 'last_price', DB::raw('COALESCE(buzz.mentions_24h, 0) AS mentions_24h')]);

        // Exact hit = intent: warm the Twitter tape before they land.
        $exact = $results->firstWhere('symbol', $symbol);

        if ($exact !== null) {
            PullTwitterForTicker::dispatchIfStale($exact->id);
        }

        return response()->json([
            'results' => $results->map(fn (Ticker $t): array => [
                'symbol' => $t->symbol,
                'name' => $t->name,
                'exchange' => $t->exchange,
                'last_price' => $t->last_price,
                'mentions_24h' => (int) $t->mentions_24h,
            ]),
        ]);
    }
}
