"""
Robustness pass on the Phase C GBM policy results.

The percentile policies in phase_c_gbm.py compute the threshold over the
full scored window (mild look-ahead). Here we use *causal* policies only:
  1. fixed absolute probability thresholds (decidable at fire time)
  2. expanding-window percentile (threshold from prior months' scores only)

Also: half-year stability split and a bootstrap CI on avg net so we don't
ship a policy built on 3 lucky trades.

Usage: .venv-ml/bin/python scripts/phase_c_robustness.py
"""

from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.calibration import CalibratedClassifierCV
from sklearn.ensemble import HistGradientBoostingClassifier

from phase_c_gbm import FEATURES, FRICTION, MIN_TRAIN, make_calibrated_gbm, make_gbm, walk_forward

rng = np.random.default_rng(42)


def stats(take: pd.DataFrame) -> str:
    if len(take) == 0:
        return "n=0"

    net = take["exit_return"] - FRICTION
    gains, losses = net[net > 0].sum(), -net[net < 0].sum()
    pf = f"{gains / losses:.2f}" if losses > 0 else "inf"

    # Bootstrap 90% CI on avg net.
    boots = [net.sample(len(net), replace=True, random_state=int(s)).mean()
             for s in rng.integers(0, 1_000_000, 500)]
    lo, hi = np.percentile(boots, [5, 95])

    return (f"n={len(take):>4}  hit={take['hit'].mean():.3f}  "
            f"avg_net={net.mean():+.4f}  CI90=[{lo:+.4f},{hi:+.4f}]  pf={pf}")


def main() -> None:
    csv = Path(__file__).resolve().parent.parent / "storage/app/phase_c_events.csv"
    df = pd.read_csv(csv)
    df["month"] = df["day"].str[:7]
    df = df.sort_values(["day", "id"]).reset_index(drop=True)

    df["p_gbm"] = walk_forward(df, make_gbm)
    df["p_iso"] = walk_forward(df, make_calibrated_gbm)

    fired = df[(df["fired"] == 1) & df["p_gbm"].notna()].copy()
    print(f"fired, scored: {len(fired)}  window {fired['day'].min()} .. {fired['day'].max()}\n")

    print("baseline (all fired):", stats(fired), "\n")

    print("-- causal policy 1: fixed absolute probability threshold --")

    for col, label in (("p_gbm", "gbm"), ("p_iso", "gbm_isotonic")):
        for t in (0.05, 0.08, 0.10, 0.15, 0.20):
            take = fired[fired[col] >= t]

            if len(take) >= 10:
                print(f"{label} p>={t:.2f}   {stats(take)}")

        print()

    print("-- causal policy 2: expanding-window percentile (prior fired scores) --")

    for col, label in (("p_gbm", "gbm"), ("p_iso", "gbm_isotonic")):
        for pct in (0.75, 0.9):
            keep = []

            for i, row in fired.iterrows():
                prior = fired[fired["day"] < row["day"]][col]

                if len(prior) >= 30 and row[col] >= prior.quantile(pct):
                    keep.append(i)

            print(f"{label} >= expanding p{int(pct * 100)}   {stats(fired.loc[keep])}")

        print()

    print("-- stability: gbm p>=0.10 by half-year --")
    take = fired[fired["p_gbm"] >= 0.10].copy()
    take["half"] = take["day"].str[:4] + "-H" + ((pd.to_datetime(take["day"]).dt.month > 6) + 1).astype(str)

    for half, grp in take.groupby("half"):
        print(f"{half}   {stats(grp)}")


if __name__ == "__main__":
    main()
