<?php

use App\Models\SignalModel;

/**
 * Hand-computable GBM artifact: one tree splitting on volume_z at 2.0
 * (left leaf −1.0, right leaf +1.0), baseline 0, and an identity-ish
 * isotonic curve with known breakpoints.
 */
function gbmModel(array $isotonic = ['x' => [0.0, 1.0], 'y' => [0.0, 1.0]]): SignalModel
{
    return new SignalModel([
        'parameters' => [
            'type' => 'gbm',
            'features' => ['volume_z', 'zscore'],
            'baseline' => 0.0,
            'trees' => [[
                ['value' => 0.0, 'is_leaf' => false, 'feature_idx' => 0, 'threshold' => 2.0, 'left' => 1, 'right' => 2],
                ['value' => -1.0, 'is_leaf' => true, 'feature_idx' => 0, 'threshold' => 0.0, 'left' => 0, 'right' => 0],
                ['value' => 1.0, 'is_leaf' => true, 'feature_idx' => 0, 'threshold' => 0.0, 'left' => 0, 'right' => 0],
            ]],
            'isotonic' => $isotonic,
        ],
    ]);
}

it('routes trees by threshold and applies the sigmoid', function () {
    $model = gbmModel();

    // volume_z <= 2 → leaf −1 → sigmoid(−1) ≈ 0.2689
    expect($model->predict(['volume_z' => 1.0, 'zscore' => 0.0]))->toEqualWithDelta(0.2689, 0.001);
    // volume_z > 2 → leaf +1 → sigmoid(1) ≈ 0.7311
    expect($model->predict(['volume_z' => 5.0, 'zscore' => 0.0]))->toEqualWithDelta(0.7311, 0.001);
});

it('treats missing features as 0.0', function () {
    expect(gbmModel()->predict([]))->toEqualWithDelta(0.2689, 0.001);
});

it('interpolates the isotonic curve between breakpoints and clips outside', function () {
    // Step curve: raw 0.2→0.05, raw 0.6→0.50; linear in between.
    $model = gbmModel(['x' => [0.2, 0.6], 'y' => [0.05, 0.50]]);

    // raw sigmoid(1) ≈ 0.7311 → above last breakpoint → clipped to 0.50
    expect($model->predict(['volume_z' => 5.0]))->toBe(0.5);
    // raw sigmoid(−1) ≈ 0.2689 → interpolated: 0.05 + 0.45·(0.2689−0.2)/0.4 ≈ 0.1275
    expect($model->predict(['volume_z' => 1.0]))->toEqualWithDelta(0.1275, 0.001);
});

it('still predicts with logistic parameters when type is absent', function () {
    $model = new SignalModel([
        'parameters' => [
            'weights' => ['volume_z' => 1.0],
            'bias' => 0.0,
            'means' => ['volume_z' => 0.0],
            'sds' => ['volume_z' => 1.0],
        ],
    ]);

    expect($model->predict(['volume_z' => 0.0]))->toBe(0.5);
});
