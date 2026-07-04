<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted confidence model plus honest out-of-sample metrics. The active
 * model scores live signals at fire time. Two parameter formats:
 *
 *  - logistic (default): weights/bias/standardization fitted by
 *    ConfidenceTrainer in PHP.
 *  - gbm: gradient-boosted trees + isotonic calibration trained by
 *    scripts/train_gbm_model.py and imported via pennyhunt:train-gbm.
 *    The artifact ships flattened tree nodes so prediction is pure PHP —
 *    no Python on the live scoring path.
 */
class SignalModel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'train_from' => 'date:Y-m-d',
            'train_to' => 'date:Y-m-d',
            'parameters' => 'array',
            'metrics' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest('id')->first();
    }

    /**
     * P(hit) for a raw (unstandardized) feature vector.
     *
     * @param  array<string, float>  $features
     */
    public function predict(array $features): float
    {
        return ($this->parameters['type'] ?? 'logistic') === 'gbm'
            ? $this->predictGbm($features)
            : $this->predictLogistic($features);
    }

    /** @param array<string, float> $features */
    protected function predictLogistic(array $features): float
    {
        $p = $this->parameters;
        $z = (float) $p['bias'];

        foreach ($p['weights'] as $feature => $weight) {
            $value = (float) ($features[$feature] ?? 0.0);
            // Constant training features can round their sd down to 0 when persisted.
            $z += $weight * ($value - $p['means'][$feature]) / max($p['sds'][$feature], 1e-9);
        }

        return round(1 / (1 + exp(-max(min($z, 30), -30))), 4);
    }

    /**
     * Sum the boosted trees' leaf values (log-odds space), sigmoid, then map
     * through the isotonic calibration curve fitted on walk-forward
     * out-of-sample scores.
     *
     * @param  array<string, float>  $features
     */
    protected function predictGbm(array $features): float
    {
        $p = $this->parameters;
        $x = array_map(fn (string $f): float => (float) ($features[$f] ?? 0.0), $p['features']);

        $z = (float) $p['baseline'];

        foreach ($p['trees'] as $nodes) {
            $i = 0;

            while (! $nodes[$i]['is_leaf']) {
                $i = $x[$nodes[$i]['feature_idx']] <= $nodes[$i]['threshold']
                    ? $nodes[$i]['left']
                    : $nodes[$i]['right'];
            }

            $z += (float) $nodes[$i]['value'];
        }

        $raw = 1 / (1 + exp(-max(min($z, 30), -30)));

        return round($this->isotonic($raw), 4);
    }

    /** Piecewise-linear interpolation over the isotonic breakpoints. */
    protected function isotonic(float $raw): float
    {
        $xs = $this->parameters['isotonic']['x'];
        $ys = $this->parameters['isotonic']['y'];
        $n = count($xs);

        if ($raw <= $xs[0]) {
            return (float) $ys[0];
        }

        if ($raw >= $xs[$n - 1]) {
            return (float) $ys[$n - 1];
        }

        // Binary search for the surrounding breakpoints.
        $lo = 0;
        $hi = $n - 1;

        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            $xs[$mid] <= $raw ? $lo = $mid : $hi = $mid;
        }

        $span = $xs[$hi] - $xs[$lo];

        return $span > 0
            ? $ys[$lo] + ($ys[$hi] - $ys[$lo]) * ($raw - $xs[$lo]) / $span
            : (float) $ys[$lo];
    }
}
