"""
Phase C model upgrade: walk-forward gradient boosting + isotonic calibration
over run #32's 24-month event set (32,619 events, 719 fired).

Protocol mirrors app/Services/Ml/ConfidenceTrainer exactly so results are
comparable with the logistic baseline:
  - events grouped by calendar month
  - each month scored by a model trained ONLY on prior months
  - min 300 training events before scoring starts

Outputs:
  - Brier / reliability quintiles for logistic vs GBM vs calibrated GBM
  - decile lift table (realized hit rate by predicted-probability decile)
  - policy simulation on FIRED events: trade only above a score percentile,
    report n / hit rate / avg net (exit_return - 5% friction) / profit factor
  - GBM feature importances (permutation, on the final 30% holdout)

Usage: .venv-ml/bin/python scripts/phase_c_gbm.py storage/app/phase_c_events.csv
"""

import sys

import numpy as np
import pandas as pd
from sklearn.calibration import CalibratedClassifierCV
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import brier_score_loss
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

FEATURES = [
    "zscore", "volume_z", "sentiment", "breadth", "pre_return_3d",
    "log_dollar_volume", "short_ratio", "atm_filed_90d", "active_shelf",
    "share_growth_12m", "market_ret_5d", "site_mention_z", "vix",
    "btc_ret_5d", "mention_streak",
]
MIN_TRAIN = 300
FRICTION = 0.05


def make_logistic():
    return make_pipeline(
        StandardScaler(),
        LogisticRegression(max_iter=2000, C=1.0),
    )


def make_gbm():
    # Shallow trees + strong regularization: 32k rows with a 4% positive
    # rate overfits fast otherwise.
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


def make_calibrated_gbm():
    return CalibratedClassifierCV(make_gbm(), method="isotonic", cv=3)


def walk_forward(df: pd.DataFrame, factory) -> pd.Series:
    """Score every event with a model trained only on earlier months."""
    scores = pd.Series(np.nan, index=df.index)
    months = sorted(df["month"].unique())

    for month in months:
        train = df[df["month"] < month]

        if len(train) < MIN_TRAIN or train["hit"].sum() < 10:
            continue

        test_idx = df.index[df["month"] == month]
        model = factory()
        model.fit(train[FEATURES], train["hit"])
        scores.loc[test_idx] = model.predict_proba(df.loc[test_idx, FEATURES])[:, 1]

    return scores


def reliability(scored: pd.DataFrame, buckets: int = 5) -> pd.DataFrame:
    q = scored.sort_values("p").reset_index(drop=True)
    q["bucket"] = pd.qcut(q.index, buckets, labels=False)

    return q.groupby("bucket").agg(
        n=("hit", "size"), predicted=("p", "mean"), realized=("hit", "mean")
    ).round(4)


def policy(fired: pd.DataFrame, score_col: str, pct: float) -> dict:
    scored = fired.dropna(subset=[score_col, "exit_return"])
    threshold = scored[score_col].quantile(pct)
    take = scored[scored[score_col] >= threshold]

    if len(take) == 0:
        return {"n": 0}

    net = take["exit_return"] - FRICTION
    gains = net[net > 0].sum()
    losses = -net[net < 0].sum()

    return {
        "n": len(take),
        "hit_rate": round(take["hit"].mean(), 4),
        "avg_net": round(net.mean(), 4),
        "pf": round(gains / losses, 2) if losses > 0 else None,
    }


def main(path: str) -> None:
    df = pd.read_csv(path)
    df["month"] = df["day"].str[:7]
    df = df.sort_values(["day", "id"]).reset_index(drop=True)

    print(f"events: {len(df)}  fired: {df.fired.sum()}  base rate: {df.hit.mean():.4f}\n")

    models = {
        "logistic": make_logistic,
        "gbm": make_gbm,
        "gbm_isotonic": make_calibrated_gbm,
    }

    for name, factory in models.items():
        df[f"p_{name}"] = walk_forward(df, factory)

    scored = df.dropna(subset=[f"p_{m}" for m in models])
    print(f"walk-forward scored: {len(scored)} events\n")

    # --- Calibration / discrimination comparison -------------------------
    for name in models:
        s = scored[[f"p_{name}", "hit"]].rename(columns={f"p_{name}": "p"})
        brier = brier_score_loss(s["hit"], s["p"])
        base = s["hit"].mean()
        print(f"== {name}  Brier {brier:.5f}  (ref {base * (1 - base):.5f})")
        print(reliability(s, buckets=10).to_string(), "\n")

    # --- Policy simulation on fired signals ------------------------------
    fired = scored[scored["fired"] == 1]
    print(f"fired signals in scored window: {len(fired)}")
    print(f"{'policy':<26}{'n':>5} {'hit':>7} {'avg_net':>9} {'pf':>6}")

    base_net = (fired["exit_return"] - FRICTION)
    base_pf = base_net[base_net > 0].sum() / -base_net[base_net < 0].sum()
    print(f"{'all fired (baseline)':<26}{len(fired):>5} {fired['hit'].mean():>7.3f} "
          f"{base_net.mean():>9.4f} {base_pf:>6.2f}")

    for name in models:
        for pct in (0.5, 0.75, 0.9, 0.95):
            r = policy(fired, f"p_{name}", pct)

            if r["n"] > 0:
                print(f"{name + ' >= p' + str(int(pct * 100)):<26}{r['n']:>5} "
                      f"{r['hit_rate']:>7.3f} {r['avg_net']:>9.4f} {str(r['pf']):>6}")

    # --- What does the GBM use? (holdout permutation importance) ---------
    split = int(len(df) * 0.7)
    train, hold = df.iloc[:split], df.iloc[split:]
    gbm = make_gbm().fit(train[FEATURES], train["hit"])
    imp = permutation_importance(
        gbm, hold[FEATURES], hold["hit"], n_repeats=5,
        random_state=42, scoring="neg_brier_score",
    )
    ranked = sorted(zip(FEATURES, imp.importances_mean), key=lambda x: -x[1])
    print("\nGBM permutation importance (holdout, Brier):")

    for feat, val in ranked:
        print(f"  {feat:<20}{val:+.5f}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "storage/app/phase_c_events.csv")
