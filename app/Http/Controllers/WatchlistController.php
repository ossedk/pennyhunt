<?php

namespace App\Http\Controllers;

use App\Models\Ticker;
use App\Models\Watchlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WatchlistController extends Controller
{
    public function index(Request $request): Response
    {
        $watchlist = Watchlist::firstOrCreate(
            ['user_id' => $request->user()->id, 'name' => 'Default'],
        );

        $tickers = $watchlist->tickers()
            ->with(['metrics' => fn ($q) => $q->where('interval', '1h')->where('bucket_start', '>=', now()->subHours(3))->orderByDesc('bucket_start')->limit(1)])
            ->get()
            ->map(fn (Ticker $ticker): array => [
                'id' => $ticker->id,
                'symbol' => $ticker->symbol,
                'name' => $ticker->name,
                'exchange' => $ticker->exchange,
                'last_price' => $ticker->last_price,
                'latest_metric' => $ticker->metrics->first()?->only([
                    'mention_count', 'unique_authors', 'weighted_sentiment', 'zscore_mentions', 'bucket_start',
                ]),
            ]);

        return Inertia::render('watchlists', [
            'watchlist' => $watchlist->only(['id', 'name']),
            'tickers' => $tickers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['symbol' => ['required', 'string', 'max:10']]);

        $ticker = Ticker::query()->where('symbol', strtoupper($validated['symbol']))->first();

        if ($ticker === null) {
            return back()->withErrors(['symbol' => 'Unknown ticker symbol.']);
        }

        $watchlist = Watchlist::firstOrCreate(
            ['user_id' => $request->user()->id, 'name' => 'Default'],
        );

        $watchlist->tickers()->syncWithoutDetaching([$ticker->id]);

        return back();
    }

    public function destroy(Request $request, Ticker $ticker): RedirectResponse
    {
        Watchlist::query()
            ->where('user_id', $request->user()->id)
            ->first()
            ?->tickers()
            ->detach($ticker->id);

        return back();
    }
}
