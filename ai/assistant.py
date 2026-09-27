#!/usr/bin/env python3
"""مساعد AI — command line entry point used by Laravel.

    python ai/assistant.py parse [--model PATH]   < {"text": "...", "accounts": [...], "currencies": [...], ...}
    python ai/assistant.py train [--extra learned.json] [--out model.json] [--report report.json]
    python ai/assistant.py try "سجل 150 شيكل على أبو أحمد"

`train --extra` adds phrases learned from merchants ([{"text", "intent"}], already privacy-masked)
and writes a report with the acceptance-gate result so Laravel can decide whether to activate it.

Standard library only; no network access and no external AI provider.
"""

import argparse
import json
import os
import random
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from daftar_nlu import __version__  # noqa: E402
from daftar_nlu.acceptance import evaluate  # noqa: E402
from daftar_nlu.classifier import IntentClassifier  # noqa: E402
from daftar_nlu.pipeline import INTENTS, parse  # noqa: E402
from daftar_nlu.training_data import build_samples  # noqa: E402

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(BASE_DIR, "model.json")

# A learned phrase counts this many times: real merchant wording beats a synthetic template.
LEARNED_WEIGHT = 3


def load_learned(path: str | None) -> list[tuple[str, str]]:
    if not path:
        return []
    with open(path, encoding="utf-8") as fh:
        rows = json.load(fh)
    return [(r["text"], r["intent"]) for r in rows if r.get("intent") in INTENTS and r.get("text")]


def train(extra: str | None = None, out: str = MODEL_PATH, report: str | None = None, verbose: bool = True) -> IntentClassifier:
    base = build_samples()
    learned = load_learned(extra)
    rng = random.Random(1)

    # Hold-out accuracy on template samples + learned phrases (learned split separately so both are measured).
    rng.shuffle(base)
    learned_shuffled = learned[:]
    rng.shuffle(learned_shuffled)
    b_cut, l_cut = int(len(base) * 0.85), int(len(learned_shuffled) * 0.8)
    train_set = base[:b_cut] + learned_shuffled[:l_cut] * LEARNED_WEIGHT
    test_set = base[b_cut:] + learned_shuffled[l_cut:]
    holdout = IntentClassifier().fit(train_set)
    accuracy = sum(holdout.predict(t)[0][0] == i for t, i in test_set) / max(1, len(test_set))

    model = IntentClassifier().fit(base + learned * LEARNED_WEIGHT)
    gate = evaluate(model)
    meta = {
        "version": __version__,
        "samples": len(base),
        "learned": len(learned),
        "holdout_accuracy": round(accuracy, 4),
        "acceptance_passed": gate["passed"],
        "acceptance_total": gate["total"],
    }
    model.save(out, meta=meta)

    if report:
        with open(report, "w", encoding="utf-8") as fh:
            json.dump({**meta, "failures": gate["failures"], "path": out}, fh, ensure_ascii=False)

    if verbose:
        print(f"trained on {len(base)} template + {len(learned)} learned samples, "
              f"hold-out {accuracy:.1%}, acceptance {gate['passed']}/{gate['total']} -> {out}")
    return model


def load_model(path: str | None = None) -> IntentClassifier:
    path = path if path and os.path.exists(path) else MODEL_PATH
    if not os.path.exists(path):
        return train(verbose=False)
    return IntentClassifier.load(path)


def main() -> int:
    sys.stdout.reconfigure(encoding="utf-8")
    cli = argparse.ArgumentParser(add_help=False)
    cli.add_argument("command", nargs="?", default="parse")
    cli.add_argument("text", nargs="*")
    cli.add_argument("--model")
    cli.add_argument("--extra")
    cli.add_argument("--out", default=MODEL_PATH)
    cli.add_argument("--report")
    args = cli.parse_args()

    if args.command == "train":
        train(args.extra, args.out, args.report)
        return 0

    if args.command == "try":
        print(json.dumps(parse(" ".join(args.text), {}, load_model(args.model)), ensure_ascii=False, indent=2))
        return 0

    if args.command == "parse":
        try:
            payload = json.loads(sys.stdin.buffer.read().decode("utf-8") or "{}")
            result = parse(str(payload.get("text", ""))[:500], payload, load_model(args.model))
        except Exception as exc:  # report errors as JSON so Laravel can show a friendly message
            print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}, ensure_ascii=False))
            return 1
        print(json.dumps(result, ensure_ascii=False))
        return 0

    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main())
