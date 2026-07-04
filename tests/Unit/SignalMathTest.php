<?php

use App\Services\Signals\SignalMath;

it('squashes z-scores through a sigmoid', function () {
    expect(SignalMath::acceleration(0.0))->toBe(0.5)
        ->and(SignalMath::acceleration(null))->toBe(0.5)
        ->and(SignalMath::acceleration(2.0))->toBeGreaterThan(0.85)->toBeLessThan(0.9)
        ->and(SignalMath::acceleration(-2.0))->toBeLessThan(0.15);
});

it('computes breadth as authors over mentions capped at 1', function () {
    expect(SignalMath::breadth(5, 10))->toBe(0.5)
        ->and(SignalMath::breadth(10, 5))->toBe(1.0)
        ->and(SignalMath::breadth(0, 0))->toBe(0.0);
});

it('shifts sentiment from -1..1 to 0..1', function () {
    expect(SignalMath::sentiment(-1.0))->toBe(0.0)
        ->and(SignalMath::sentiment(0.0))->toBe(0.5)
        ->and(SignalMath::sentiment(1.0))->toBe(1.0)
        ->and(SignalMath::sentiment(null))->toBe(0.5);
});

it('computes the weighted composite with all components', function () {
    $composite = SignalMath::composite([
        'acceleration' => 1.0,
        'breadth' => 1.0,
        'sentiment' => 1.0,
        'cross_source' => 1.0,
    ]);

    expect($composite)->toBe(1.0);
});

it('matches the live engine weighting when all components are present', function () {
    $components = ['acceleration' => 0.9, 'breadth' => 0.5, 'sentiment' => 0.7, 'cross_source' => 0.0];
    $expected = 0.9 * 0.40 + 0.5 * 0.20 + 0.7 * 0.25;

    expect(SignalMath::composite($components))->toEqualWithDelta($expected, 0.0001);
});

it('renormalizes weights when a component is unavailable', function () {
    // cross_source null: remaining weights (.40 + .20 + .25 = .85) renormalize to 1
    $composite = SignalMath::composite([
        'acceleration' => 1.0,
        'breadth' => 1.0,
        'sentiment' => 1.0,
        'cross_source' => null,
    ]);

    expect($composite)->toEqualWithDelta(1.0, 0.0001);

    $mixed = SignalMath::composite([
        'acceleration' => 0.8,
        'breadth' => 0.6,
        'sentiment' => 0.5,
        'cross_source' => null,
    ]);

    expect($mixed)->toEqualWithDelta((0.8 * 0.40 + 0.6 * 0.20 + 0.5 * 0.25) / 0.85, 0.0001);
});
