"""A tiny multinomial Naive Bayes intent classifier (pure Python, JSON-serialisable)."""

import json
import math
from collections import Counter, defaultdict

from .text import features


class IntentClassifier:
    def __init__(self, alpha: float = 0.3):
        self.alpha = alpha
        self.priors: dict[str, float] = {}
        self.likelihoods: dict[str, dict[str, float]] = {}
        self.unseen: dict[str, float] = {}
        self.vocab: set[str] = set()

    # ------------------------------------------------------------------ training
    def fit(self, samples: list[tuple[str, str]]) -> "IntentClassifier":
        class_counts: Counter = Counter()
        feature_counts: dict[str, Counter] = defaultdict(Counter)

        for text, intent in samples:
            class_counts[intent] += 1
            # Binary counts per sample: repeated n-grams in one sentence don't dominate.
            feature_counts[intent].update(set(features(text)))

        self.vocab = {f for counts in feature_counts.values() for f in counts}
        total = sum(class_counts.values())
        size = len(self.vocab)

        for intent, count in class_counts.items():
            self.priors[intent] = math.log(count / total)
            counts = feature_counts[intent]
            denominator = sum(counts.values()) + self.alpha * size
            self.likelihoods[intent] = {f: math.log((c + self.alpha) / denominator) for f, c in counts.items()}
            self.unseen[intent] = math.log(self.alpha / denominator)

        return self

    # ---------------------------------------------------------------- inference
    def predict(self, text: str, top: int = 3) -> list[tuple[str, float]]:
        """Return the `top` intents with calibrated-ish probabilities (softmax of log scores)."""
        feats = [f for f in set(features(text)) if f in self.vocab]

        if not feats:
            return [("unknown", 1.0)]

        scores = {}
        for intent, prior in self.priors.items():
            table = self.likelihoods[intent]
            miss = self.unseen[intent]
            scores[intent] = prior + sum(table.get(f, miss) for f in feats)

        # Temper the scores: NB is over-confident on overlapping n-gram features.
        temperature = max(1.0, len(feats) / 6)
        best = max(scores.values())
        exp = {i: math.exp((s - best) / temperature) for i, s in scores.items()}
        norm = sum(exp.values())

        ranked = sorted(((i, v / norm) for i, v in exp.items()), key=lambda x: x[1], reverse=True)
        return ranked[:top]

    def coverage(self, text: str) -> float:
        """Share of the sentence's features the model has ever seen; low = off-topic text."""
        feats = set(features(text))
        return sum(f in self.vocab for f in feats) / len(feats) if feats else 0.0

    # ------------------------------------------------------------- persistence
    def to_dict(self) -> dict:
        return {
            "alpha": self.alpha,
            "priors": self.priors,
            "unseen": self.unseen,
            "likelihoods": {i: {f: round(v, 5) for f, v in t.items()} for i, t in self.likelihoods.items()},
        }

    @classmethod
    def from_dict(cls, data: dict) -> "IntentClassifier":
        model = cls(data.get("alpha", 0.3))
        model.priors = data["priors"]
        model.unseen = data["unseen"]
        model.likelihoods = data["likelihoods"]
        model.vocab = {f for t in model.likelihoods.values() for f in t}
        return model

    def save(self, path: str, meta: dict | None = None) -> None:
        with open(path, "w", encoding="utf-8") as fh:
            json.dump({"meta": meta or {}, "model": self.to_dict()}, fh, ensure_ascii=False, separators=(",", ":"))

    @classmethod
    def load(cls, path: str) -> "IntentClassifier":
        with open(path, encoding="utf-8") as fh:
            return cls.from_dict(json.load(fh)["model"])
