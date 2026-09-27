"""Arabic text normalisation, tokenisation and feature extraction."""

import re

_DIACRITICS = re.compile(r"[ؐ-ًؚ-ٰٟۖ-ۭ]")
_TATWEEL = "ـ"

_CHAR_MAP = str.maketrans({
    "أ": "ا", "إ": "ا", "آ": "ا", "ٱ": "ا",
    "ى": "ي", "ئ": "ي", "ؤ": "و", "ة": "ه",
    "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4",
    "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
    "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4",
    "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
    "٫": ".", "٬": "", "،": " ", "؟": " ", "؛": " ",
})

NUM = "xnum"  # placeholder for any number
NAME = "xname"  # placeholder for a matched account name

_NUMBER = re.compile(r"\d+(?:[.,]\d+)?")
_THOUSANDS = re.compile(r"(?<=\d),(?=\d{3}\b)")
_NON_WORD = re.compile(r"[^\w\s.]", re.UNICODE)
_SPACES = re.compile(r"\s+")

# Clitics glued to the front of words ("وسجل", "بالدولار", "للمورد").
_PREFIXES = ("وبال", "وال", "بال", "لل", "فال", "كال", "ال", "و", "ب", "ل", "ف")


def normalize(text: str) -> str:
    """Lower-noise form used everywhere: no diacritics, unified letters, latin digits."""
    text = _DIACRITICS.sub("", text or "").replace(_TATWEEL, "")
    text = text.translate(_CHAR_MAP).lower()
    text = _THOUSANDS.sub("", text)  # "1,500" -> "1500"
    text = _NON_WORD.sub(" ", text)
    return _SPACES.sub(" ", text).strip()


def tokens(text: str) -> list[str]:
    return normalize(text).split()


def strip_prefix(token: str) -> str:
    """Remove one leading clitic when enough of the word remains."""
    for prefix in _PREFIXES:
        if token.startswith(prefix) and len(token) - len(prefix) >= 3:
            return token[len(prefix):]
    return token


def mask_numbers(text: str) -> str:
    return _NUMBER.sub(" xnum ", text)


def features(text: str) -> list[str]:
    """Bag of features for the classifier: words, stems, bigrams and char 3/4-grams."""
    words = mask_numbers(normalize(text)).split()
    feats: list[str] = []

    for word in words:
        feats.append("w:" + word)
        stem = strip_prefix(word)
        if stem != word:
            feats.append("w:" + stem)
        if word in (NUM, NAME):
            continue
        padded = f"<{stem}>"
        for n in (3, 4):
            feats.extend("c:" + padded[i:i + n] for i in range(len(padded) - n + 1))

    feats.extend(f"b:{a}_{b}" for a, b in zip(words, words[1:]))
    return feats
