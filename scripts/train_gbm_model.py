"""
Train the production GBM confidence model and export it as a portable JSON
artifact that PHP can evaluate natively (no Python on the live scoring path).

Protocol (mirrors ConfidenceTrainer + the Phase C research scripts):
  1. Walk-forward score all events (monthly refits, no look-ahead) — these
     out-of-sample probabilities give honest metrics AND the training data
     for the isotonic calibration layer.
  2. Fit the final GBM on the full event set (that model ships).
  3. Fit isotonic regression on the walk-forward (raw p, label) pairs so the
     shipped probabilities are calibrated for Kelly sizing.
  4. Export: boosted trees (flattened nodes), baseline, isotonic breakpoints,
     metrics, and parity vectors (5 sample rows with expected outputs) that
     the PHP side re-checks after import.

Usage:
  .venv-ml/bin/python scripts/train_gbm_model.py <events.csv> <out.json>
"""

import json
import sys

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.isotonic import IsotonicRegression
from sklearn.metrics import brier_score_loss

# Must mirror ConfidenceTrainer::FEATURES (the export command writes columns
# in that order; unknown columns here would silently train on garbage).
FEATURES = [
    "zscore", "volume_z", "sentiment", "breadth", "pre_return_3d",
    "log_dollar_volume", "short_ratio", "atm_filed_90d", "active_shelf",
    "share_growth_12m", "market_ret_5d", "site_mention_z", "vix",
    "btc_ret_5d", "mention_streak",
    "llm_coverage", "llm_direction", "llm_conviction", "llm_pump_suspicion",
    "llm_dd_share", "llm_hype_share", "llm_news_share", "llm_catalyst_share",
    "rvol", "atr_pct", "range_expansion", "dist_52w_high", "up_streak", "gap_open",
    "sector_heat", "sector_mention_z", "smallcap_rel_20d", "xbi_ret_5d",
    "insider_buys_90d", "insider_net_value_90d", "news_catalyst_7d", "news_offering_7d",
]
MIN_TRAIN = 300


def make_gbm() -> HistGradientBoostingClassifier:
    # Must stay in sync with the Phase C research configuration
    # (docs/audits/2026-07-04-phase-c-gbm.md).
    return HistGradientBoostingClassifier(
        max_iter=300,
        learning_rate=0.05,
        max_depth=3,
        min_samples_leaf=60,
        l2_regularization=1.0,
        max_features=0.8,
        early_stopping=True,
        validation_fraction=0.15,
        random_state=42,
    )


def walk_forward(df: pd.DataFrame) -> pd.Series:
    scores = pd.Series(np.nan, index=df.index)

    for month in sorted(df["month"].unique()):
        train = df[df["month"] < month]

        if len(train) < MIN_TRAIN or train["hit"].sum() < 10:
            continue

        test_idx = df.index[df["month"] == month]
        model = make_gbm().fit(train[FEATURES], train["hit"])
        scores.loc[test_idx] = model.predict_proba(df.loc[test_idx, FEATURES])[:, 1]

    return scores


def export_trees(model: HistGradientBoostingClassifier) -> tuple[list, float]:
    """Flatten each predictor's node array into JSON-safe dicts."""
    trees = []

    for (predictor,) in model._predictors:
        nodes = []

        for node in predictor.nodes:
            nodes.append({
                "value": float(node["value"]),
                "is_leaf": bool(node["is_leaf"]),
                "feature_idx": int(node["feature_idx"]),
                "threshold": float(node["num_threshold"]),
                "left": int(node["left"]),
                "right": int(node["right"]),
            })

        trees.append(nodes)

    return trees, float(model._baseline_prediction.ravel()[0])


def reliability(p: np.ndarray, y: np.ndarray, buckets: int = 10) -> list:
    order = np.argsort(p)
    out = []

    for chunk in np.array_split(order, buckets):
        out.append({
            "count": len(chunk),
            "predicted": round(float(p[chunk].mean()), 4),
            "realized": round(float(y[chunk].mean()), 4),
        })

    return out


def main(csv_path: str, out_path: str) -> None:
    df = pd.read_csv(csv_path)
    df["month"] = df["day"].str[:7]
    df = df.sort_values(["day", "id"]).reset_index(drop=True)

    # Older exports predate some features; absent = the neutral-zero vector
    # the PHP feature builder produces for unknown values.
    for feature in FEATURES:
        if feature not in df.columns:
            df[feature] = 0.0

    # 1. Honest out-of-sample scores.
    df["p_oos"] = walk_forward(df)
    oos = df.dropna(subset=["p_oos"])

    # 2. Final model on everything.
    final = make_gbm().fit(df[FEATURES], df["hit"])
    trees, baseline = export_trees(final)

    # 3. Calibration layer from out-of-sample scores only.
    iso = IsotonicRegression(out_of_bounds="clip", y_min=0.0, y_max=1.0)
    iso.fit(oos["p_oos"], oos["hit"])

    p_raw = oos["p_oos"].to_numpy()
    y = oos["hit"].to_numpy()
    p_cal = iso.predict(p_raw)

    # Where does the research trade tier (raw p >= 0.15) land after calibration?
    tier_calibrated = float(iso.predict([0.15])[0])

    metrics = {
        "oos_events": int(len(oos)),
        "base_rate": round(float(y.mean()), 4),
        "brier_raw": round(float(brier_score_loss(y, p_raw)), 5),
        "brier_calibrated": round(float(brier_score_loss(y, p_cal)), 5),
        "brier_reference": round(float(y.mean() * (1 - y.mean())), 5),
        "reliability": reliability(p_cal, y),
        "trade_tier": {"raw_p": 0.15, "calibrated_p": round(tier_calibrated, 4)},
    }

    # 4. Parity vectors: PHP must reproduce these exactly (tolerance 1e-6).
    sample = df.sample(5, random_state=7)
    raw_sample = final.predict_proba(sample[FEATURES])[:, 1]
    parity = [
        {
            "features": {f: float(v) for f, v in zip(FEATURES, row)},
            "raw_p": float(raw),
            "calibrated_p": float(iso.predict([raw])[0]),
        }
        for row, raw in zip(sample[FEATURES].to_numpy(), raw_sample)
    ]

    artifact = {
        "type": "gbm",
        "features": FEATURES,
        "baseline": baseline,
        "trees": trees,
        "isotonic": {
            "x": [float(v) for v in iso.X_thresholds_],
            "y": [float(v) for v in iso.y_thresholds_],
        },
        "train_events": int(len(df)),
        "train_from": df["day"].min(),
        "train_to": df["day"].max(),
        "n_trees": len(trees),
        "metrics": metrics,
        "parity": parity,
    }

    with open(out_path, "w") as fh:
        json.dump(artifact, fh)

    print(json.dumps({
        "ok": True, "n_trees": len(trees), **metrics,
        "artifact_bytes": len(json.dumps(artifact)),
    }))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
