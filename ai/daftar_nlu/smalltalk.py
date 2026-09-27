"""Greetings, courtesies and general questions ("small talk").

The phrases live in Laravel's config/assistant_smalltalk.php and arrive with each request as
[{"key": "...", "patterns": [...]}], so they can be edited without retraining the model.
"""

from .entities import Tok
from .text import normalize

# Words that don't change the meaning of a greeting ("مرحبا يا صاحبي", "شكرا يا مساعد").
FILLERS = {"يا", "اخي", "اخوي", "خيي", "صديقي", "صاحبي", "حبيبي", "استاذ", "عزيزي", "مساعد", "ai", "الذكي",
           "بوت", "و", "الله", "كتير", "جدا", "والله", "كمان", "ريت", "انت", "ابو"}


def match(toks: list[Tok], entries: list[dict]) -> dict | None:
    """Find small-talk phrases in the sentence.

    Returns {"keys": [...in sentence order], "best": key of the longest phrase, "coverage": share of
    meaningful tokens covered by small talk}, or None.
    """
    found = []  # (start, length, key)

    for order, entry in enumerate(entries):
        for pattern in entry.get("patterns", []):
            parts = normalize(pattern).split()
            n = len(parts)
            if not n:
                continue
            for i in range(len(toks) - n + 1):
                if all(parts[k] == toks[i + k].norm or parts[k] in toks[i + k].variants for k in range(n)):
                    found.append((i, n, order, entry["key"]))

    if not found:
        return None

    # Longest phrase first; drop matches overlapping an already chosen (longer) one.
    chosen, covered = [], set()
    for start, length, order, key in sorted(found, key=lambda f: (-f[1], f[2], f[0])):
        span = set(range(start, start + length))
        if span & covered:
            continue
        chosen.append((start, length, key))
        covered |= span

    meaningful = [i for i, t in enumerate(toks) if t.norm not in FILLERS]
    coverage = sum(1 for i in meaningful if i in covered) / max(1, len(meaningful))

    keys = []
    for _, _, key in sorted(chosen):
        if key not in keys:
            keys.append(key)

    return {"keys": keys, "best": chosen[0][2], "coverage": round(coverage, 3)}
