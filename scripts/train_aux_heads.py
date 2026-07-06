"""Auxiliary heads for Phase F, walk-forward (monthly refits, no look-ahead).

Reads the events CSV exported by pennyhunt:train-aux-heads (features +
label_moonshot + label_meta), trains two HistGradientBoosting heads:

  moonshot head — P(best_close_5d >= +75%): the fat tail specifically.
  meta head     — P(phase-E trade of this event nets > 0): trade-outcome
                  prediction under the actual exit discipline (meta-labeling).

Writes {out}_aux.csv with id, moonshot_p, meta_p (out-of-sample only), and
{out}_moonshot.json — the FINAL moonshot model (trained on everything) in
the same trees format as train_gbm_model.py, for native PHP scoring at
fire time (raw probability; live gating uses a raw threshold).

Usage: python train_aux_heads.py events.csv out_prefix
"""

import json
import sys

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import roc_auc_score

# Must mirror ConfidenceTrainer::FEATURES (same export pipeline as train_gbm_model.py).
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
    "or_return_30m", "vwap_dist_30m", "or_vol_share", "gap_faded",
    "si_days_to_cover", "si_pct_change", "ftd_log", "borrow_fee", "halted_5d",
]
MIN_TRAIN = 500


def make_model() -> HistGradientBoostingClassifier:
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


def walk_forward(df: pd.DataFrame, label: str) -> pd.Series:
    scores = pd.Series(np.nan, index=df.index)

    for month in sorted(df["month"].unique()):
        train = df[df["month"] < month].dropna(subset=[label])

        if len(train) < MIN_TRAIN or train[label].sum() < 15:
            continue

        test_idx = df.index[df["month"] == month]
        model = make_model().fit(train[FEATURES], train[label])
        scores.loc[test_idx] = model.predict_proba(df.loc[test_idx, FEATURES])[:, 1]

    return scores


def main(csv_path: str, out_prefix: str) -> None:
    df = pd.read_csv(csv_path)
    df["month"] = df["day"].str[:7]
    df = df.sort_values(["day", "id"]).reset_index(drop=True)

    for feature in FEATURES:
        if feature not in df.columns:
            df[feature] = 0.0

    df["moonshot_p"] = walk_forward(df, "label_moonshot")
    df["meta_p"] = walk_forward(df, "label_meta")

    scored = df.dropna(subset=["moonshot_p"])

    if len(scored) > 0:
        print(f"moonshot head: {len(scored)} OOS events, base {scored['label_moonshot'].mean():.4f}, "
              f"AUC {roc_auc_score(scored['label_moonshot'], scored['moonshot_p']):.4f}")

    scored_meta = df.dropna(subset=["meta_p", "label_meta"])

    if len(scored_meta) > 0:
        print(f"meta head: {len(scored_meta)} OOS events, base {scored_meta['label_meta'].mean():.4f}, "
              f"AUC {roc_auc_score(scored_meta['label_meta'], scored_meta['meta_p']):.4f}")

    out = df.dropna(subset=["moonshot_p"])[["id", "moonshot_p", "meta_p"]]
    out.to_csv(f"{out_prefix}_aux.csv", index=False)
    print(f"wrote {len(out)} rows to {out_prefix}_aux.csv")

    # Final moonshot model on all data, exported for PHP evaluation.
    final = make_model().fit(df[FEATURES], df["label_moonshot"])
    trees = []

    for (predictor,) in final._predictors:
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

    sample = df.sample(5, random_state=7)
    parity = [
        {
            "features": {f: float(v) for f, v in zip(FEATURES, row)},
            "raw_p": float(raw),
            "calibrated_p": float(raw),  # no isotonic layer: raw threshold gating
        }
        for row, raw in zip(sample[FEATURES].to_numpy(), final.predict_proba(sample[FEATURES])[:, 1])
    ]

    artifact = {
        "type": "gbm",
        "features": FEATURES,
        "baseline": float(final._baseline_prediction.ravel()[0]),
        "trees": trees,
        "isotonic": None,
        "parity": parity,
        "train_from": str(df["day"].min()),
        "train_to": str(df["day"].max()),
        "train_events": int(len(df)),
        "metrics": {
            "label": "best_close_5d >= 0.75",
            "oos_events": int(len(scored)),
            "base_rate": round(float(scored["label_moonshot"].mean()), 4) if len(scored) else None,
            "auc": round(float(roc_auc_score(scored["label_moonshot"], scored["moonshot_p"])), 4) if len(scored) else None,
        },
    }

    with open(f"{out_prefix}_moonshot.json", "w") as fh:
        json.dump(artifact, fh)

    print(f"wrote moonshot artifact to {out_prefix}_moonshot.json")


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
