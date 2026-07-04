<?php

namespace App\Services\Ml;

/**
 * Dependency-free batch-gradient-descent logistic regression with feature
 * standardization. Deterministic, small, shared by the weight fitter and the
 * confidence trainer; easily replaced by the Python sidecar if the strategy
 * graduates.
 */
class LogisticRegression
{
    /** @param array<int, string> $features */
    public function __construct(
        protected array $features,
        protected int $epochs = 400,
        protected float $learningRate = 0.1,
    ) {}

    /**
     * @param  array<int, array{label: float, features: array<string, float>}>  $train
     * @return array{weights: array<string, float>, bias: float, means: array<string, float>, sds: array<string, float>}
     */
    public function fit(array $train): array
    {
        [$means, $sds] = $this->standardizer($train);

        $weights = array_fill_keys($this->features, 0.0);
        $bias = 0.0;
        $n = count($train);

        // Pre-standardize once (the loop below runs epochs × n times).
        $rows = array_map(fn (array $row): array => [
            'label' => $row['label'],
            'x' => $this->standardize($row['features'], $means, $sds),
        ], $train);

        for ($epoch = 0; $epoch < $this->epochs; $epoch++) {
            $gradW = array_fill_keys($this->features, 0.0);
            $gradB = 0.0;

            foreach ($rows as $row) {
                $z = $bias;

                foreach ($this->features as $f) {
                    $z += $weights[$f] * $row['x'][$f];
                }

                $err = self::sigmoid($z) - $row['label'];

                foreach ($this->features as $f) {
                    $gradW[$f] += $err * $row['x'][$f];
                }

                $gradB += $err;
            }

            foreach ($this->features as $f) {
                $weights[$f] -= $this->learningRate * $gradW[$f] / $n;
            }

            $bias -= $this->learningRate * $gradB / $n;
        }

        return ['weights' => $weights, 'bias' => $bias, 'means' => $means, 'sds' => $sds];
    }

    /**
     * @param  array{weights: array<string, float>, bias: float, means: array<string, float>, sds: array<string, float>}  $params
     * @param  array<string, float>  $features  raw (unstandardized)
     */
    public static function predict(array $params, array $features): float
    {
        $z = $params['bias'];

        foreach ($params['weights'] as $f => $w) {
            $z += $w * (($features[$f] ?? 0.0) - $params['means'][$f]) / $params['sds'][$f];
        }

        return self::sigmoid($z);
    }

    public static function sigmoid(float $z): float
    {
        return 1 / (1 + exp(-max(min($z, 30), -30)));
    }

    /**
     * @param  array<int, array{label: float, features: array<string, float>}>  $train
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    protected function standardizer(array $train): array
    {
        $means = [];
        $sds = [];

        foreach ($this->features as $f) {
            $values = array_map(fn ($row) => $row['features'][$f] ?? 0.0, $train);
            $mean = array_sum($values) / count($values);
            $var = 0.0;

            foreach ($values as $v) {
                $var += ($v - $mean) ** 2;
            }

            $means[$f] = $mean;
            $sds[$f] = max(sqrt($var / count($values)), 1e-9);
        }

        return [$means, $sds];
    }

    /**
     * @param  array<string, float>  $features
     * @param  array<string, float>  $means
     * @param  array<string, float>  $sds
     * @return array<string, float>
     */
    protected function standardize(array $features, array $means, array $sds): array
    {
        $out = [];

        foreach ($this->features as $f) {
            $out[$f] = (($features[$f] ?? 0.0) - $means[$f]) / $sds[$f];
        }

        return $out;
    }
}
