"""Entity extraction: amounts, currencies, account names, days, limits, phones, categories."""

import re
from difflib import SequenceMatcher

from .text import normalize, strip_prefix

# ----------------------------------------------------------------------------- tokens


class Tok:
    """A token with its original spelling (for notes/names shown to the user) and normalised form."""

    __slots__ = ("raw", "norm", "variants")

    def __init__(self, raw: str):
        self.raw = raw.strip("،,.؟?!:;\"'()[]")
        self.norm = normalize(raw).replace(" ", "")
        stem = strip_prefix(self.norm)
        variants = {self.norm, stem}
        # Single-letter clitics even on short words: "لعلي" -> "علي", "بالدولار" handled by strip_prefix.
        for clitic in ("لل", "ل", "ب", "و", "ف"):
            if self.norm.startswith(clitic) and len(self.norm) - len(clitic) >= 2:
                variants.add(self.norm[len(clitic):])
        self.variants = variants


def tokenize(text: str) -> list[Tok]:
    text = re.sub(r"(\d)([^\d\s.,])", r"\1 \2", text)      # "150$" -> "150 $", "150شيكل"
    text = re.sub(r"([^\d\s.,+])(\d)", r"\1 \2", text)      # "بـ1200" -> "بـ 1200"
    return [t for t in (Tok(w) for w in text.split()) if t.norm or t.raw in ("$", "₪", "€")]


# --------------------------------------------------------------------------- numbers

WORD_NUMBERS = {
    "واحد": 1, "اثنين": 2, "اتنين": 2, "ثلاث": 3, "تلات": 3, "ثلاثه": 3, "تلاته": 3, "اربع": 4, "اربعه": 4,
    "خمس": 5, "خمسه": 5, "سته": 6, "ست": 6, "سبع": 7, "سبعه": 7, "ثمان": 8, "تمن": 8, "تمانيه": 8, "تسع": 9, "تسعه": 9,
    "عشر": 10, "عشره": 10, "عشرين": 20, "ثلاثين": 30, "تلاتين": 30, "اربعين": 40, "خمسين": 50,
    "ستين": 60, "سبعين": 70, "ثمانين": 80, "تمانين": 80, "تسعين": 90,
    "ميه": 100, "مية": 100, "مئه": 100, "مائه": 100, "ميتين": 200, "مئتين": 200, "مئتان": 200, "ميتان": 200,
    "تلتميه": 300, "ثلاثمائه": 300, "ثلاثميه": 300, "اربعميه": 400, "اربعمائه": 400, "خمسميه": 500, "خمسمائه": 500,
    "ستميه": 600, "سبعميه": 700, "تمنميه": 800, "ثمانمائه": 800, "تسعميه": 900,
    "الف": 1000, "الفين": 2000, "الفان": 2000, "مليون": 1_000_000,
    "نص": 0.5, "ربع": 0.25,
}
MULTIPLIERS = {"الف": 1000, "الاف": 1000, "الوف": 1000, "k": 1000, "مليون": 1_000_000, "ملايين": 1_000_000}
_NUM_RE = re.compile(r"^\+?\d+(?:[.,]\d+)?$")

DAY_UNITS = {"يوم": 1, "ايام": 1, "اسبوع": 7, "اسابيع": 7, "اسبوعين": 14, "شهر": 30, "اشهر": 30, "شهور": 30,
             "شهرين": 60, "سنه": 365, "سنين": 365, "سنتين": 730}
LIMIT_NOUNS = {"زبائن", "زباين", "زبون", "عملاء", "عميل", "مديونين", "مدينين", "اشخاص", "ناس", "حسابات", "ديون", "واحد"}
LIMIT_TRIGGERS = {"اكثر", "اكتر", "اكبر", "اعلى", "توب", "top", "اول"}


def _numeric(tok: Tok) -> float | None:
    for v in (tok.norm, *tok.variants):
        if _NUM_RE.match(v):
            return float(v.replace(",", "."))
    return None


def _is_phone(tok: Tok) -> bool:
    digits = re.sub(r"\D", "", tok.norm)
    return len(digits) >= 7 and (tok.norm.startswith("0") or tok.norm.startswith("+") or len(digits) >= 9)


def extract_amount(toks: list[Tok], skip: set[int]) -> tuple[float | None, set[int]]:
    """First money amount in the sentence, e.g. "150", "1,500", "3 الاف", "الف و خمسميه", "ميتين"."""
    i = 0
    while i < len(toks):
        if i in skip or _is_phone(toks[i]):
            i += 1
            continue

        value = _numeric(toks[i])
        word = next((WORD_NUMBERS[v] for v in toks[i].variants if v in WORD_NUMBERS), None)

        if value is None and word is None:
            i += 1
            continue

        span = {i}
        total = value if value is not None else word
        j = i + 1

        # "3 الاف", "1.5 مليون"
        if j < len(toks) and (m := next((MULTIPLIERS[v] for v in toks[j].variants if v in MULTIPLIERS), None)):
            total *= m if total < 1000 else 1
            span.add(j)
            j += 1

        # "الف و خمسميه", "ميه وخمسين"
        while j < len(toks):
            if toks[j].norm == "و" and j + 1 < len(toks):
                candidates, step = toks[j + 1].variants, 2
            else:
                candidates, step = {v[1:] for v in toks[j].variants if v.startswith("و") and len(v) > 1}, 1
            add = next((WORD_NUMBERS[c] for c in candidates if c in WORD_NUMBERS), None)
            if add is None or add >= total:
                break
            total += add
            span.update(range(j, j + step))
            j += step

        return round(total, 4), span

    return None, set()


def extract_days(toks: list[Tok]) -> tuple[int | None, set[int]]:
    for i, tok in enumerate(toks):
        unit = next((DAY_UNITS[v] for v in tok.variants if v in DAY_UNITS), None)
        if unit is None:
            continue
        prev = _numeric(toks[i - 1]) if i > 0 else None
        if prev is not None:
            return int(prev * unit), {i - 1, i}
        return unit, {i}
    return None, set()


def extract_limit(toks: list[Tok]) -> tuple[int | None, set[int]]:
    for i, tok in enumerate(toks):
        value = _numeric(tok)
        if value is None or value > 100:
            continue
        after = toks[i + 1].variants if i + 1 < len(toks) else set()
        before = toks[i - 1].variants if i > 0 else set()
        if after & LIMIT_NOUNS or before & LIMIT_TRIGGERS:
            return int(value), {i}
    return None, set()


def extract_phone(text: str) -> str | None:
    digits = normalize(text)
    match = re.search(r"(\+?\d[\d\s-]{6,16}\d)", digits)
    if not match:
        return None
    phone = re.sub(r"[\s-]", "", match.group(1))
    return phone if len(re.sub(r"\D", "", phone)) >= 7 else None


# ------------------------------------------------------------------------ currencies

# canonical key -> words that identify it in a currency's code/name and in text
CURRENCY_ALIASES = {
    "ils": {"شيكل", "شيكلات", "شواكل", "شيقل", "شيقلات", "ils", "nis", "₪"},
    "usd": {"دولار", "دولارات", "usd", "$"},
    "jod": {"دينار", "دنانير", "jod", "jd"},
    "sar": {"سعودي", "sar"},
    "egp": {"جنيه", "مصري", "egp"},
    "yer": {"يمني", "yer"},
    "eur": {"يورو", "eur", "€"},
    "aed": {"درهم", "اماراتي", "aed"},
}


def _currency_key(currency: dict) -> str | None:
    blob = set(normalize(f"{currency.get('code', '')} {currency.get('name', '')}").split())
    blob |= {strip_prefix(w) for w in blob}
    for key, aliases in CURRENCY_ALIASES.items():
        if blob & aliases:
            return key
    return None


def extract_currency(toks: list[Tok], currencies: list[dict]) -> tuple[dict | None, set[int]]:
    """Match a currency mentioned in text against the tenant's own currencies."""
    by_key: dict[str, dict] = {}
    by_word: dict[str, dict] = {}
    for c in currencies:
        if key := _currency_key(c):
            by_key.setdefault(key, c)
        for w in normalize(f"{c.get('code', '')} {c.get('name', '')}").split():
            if len(w) >= 3:
                by_word.setdefault(w, c)

    for i, tok in enumerate(toks):
        words = tok.variants | {tok.raw.lower()}
        # "ريال" alone: prefer a following qualifier ("ريال يمني"), else Saudi.
        if "ريال" in words:
            nxt = toks[i + 1].variants if i + 1 < len(toks) else set()
            key = "yer" if nxt & CURRENCY_ALIASES["yer"] else "sar"
            if key in by_key:
                return by_key[key], {i, i + 1} if nxt & (CURRENCY_ALIASES["yer"] | CURRENCY_ALIASES["sar"]) else {i}
        for key, aliases in CURRENCY_ALIASES.items():
            if words & aliases and key in by_key:
                span = {i}
                if i + 1 < len(toks) and toks[i + 1].norm in ("اردني", "امريكي", "سعودي", "مصري", "اسرائيلي", "يمني"):
                    span.add(i + 1)
                return by_key[key], span
        for w in words:
            if w in by_word:
                return by_word[w], {i}

    return None, set()


# -------------------------------------------------------------------------- accounts

NAME_STOPWORDS = {
    "على", "علي", "من", "ل", "له", "عليه", "عند", "حساب", "سجل", "قيد", "حط", "دفعه", "دفعت", "قبضت", "استلمت",
    "سدد", "سددت", "بضاعه", "تحت", "الحساب", "كم", "شو", "باقي", "مين", "اعرض", "جديد", "باسم", "اسمه", "رقم",
    "جوال", "رقمه", "هاتف", "موبايل", "عميل", "زبون", "مورد", "المورد", "الزبون", "اليوم", "دين", "مبلغ", "ب",
    "الي", "لي", "عندي", "كشف", "رساله", "واتساب", "واتس", "ابعت", "ارسل", "جهز", "لحساب", "اخر", "اخر", "متى",
    "و", "في", "هل", "قرب", "الحد", "السقف", "فاتوره", "وصلت", "ضيف", "افتح", "اضف", "بدي", "لحاله", "حاليا",
    "بالدولار", "بالشيكل", "بالدينار", "دولار", "شيكل", "دينار", "ريال", "جنيه", "الف", "ميه", "عن", "مع",
}
_WEAK_NAME_TOKENS = {"ابو", "ام", "شركه", "محل", "مورد", "موزع", "سوبرماركت", "الحاج", "ابن", "بن", "بيت", "مؤسسه"}


def _similar(a: str, b: str) -> float:
    return SequenceMatcher(None, a, b).ratio()


def match_accounts(toks: list[Tok], accounts: list[dict], skip: set[int]) -> list[dict]:
    """Score every account against the sentence. Returns candidates (best first) with their token span."""
    results = []
    # "على" normalises to "علي"; the preposition must never count as the name Ali.
    prepositions = {i for i, t in enumerate(toks) if t.raw in ("على", "عليه", "عليها", "علينا")}
    skip = skip | prepositions

    for account in accounts:
        parts = normalize(account["name"]).split()
        if not parts:
            continue
        n = len(parts)
        best = (0.0, set(), "none")
        clitic = ""

        for i in range(len(toks) - n + 1):
            window = range(i, i + n)
            if any(k in skip for k in window):
                continue
            if all(parts[k] in toks[i + k].variants for k in range(n)):
                if 1.0 + 0.02 * n > best[0]:
                    first = toks[i].norm
                    clitic = first[: len(first) - len(parts[0])] if first.endswith(parts[0]) else ""
                    best = (1.0 + 0.02 * n, set(window), "exact")
                continue
            ratio = sum(max(_similar(parts[k], v) for v in toks[i + k].variants) for k in range(n)) / n
            if ratio >= 0.82:
                best = max(best, (0.8 * ratio, set(window), "fuzzy"), key=lambda x: x[0])

        if best[0] < 0.6 and n > 1:
            strong = [p for p in parts if p not in _WEAK_NAME_TOKENS and len(p) >= 3]
            for i, tok in enumerate(toks):
                # Colloquial "علي / لي / الي" (on me / mine) must not partially match "علي حسن".
                if i in skip or tok.norm in {"علي", "لي", "الي", "مني"}:
                    continue
                if strong and strong[0] in tok.variants:
                    best = max(best, (0.62, {i}, "partial"), key=lambda x: x[0])
                elif any(p in tok.variants for p in strong[1:]):
                    best = max(best, (0.56, {i}, "partial"), key=lambda x: x[0])

        if best[0] >= 0.55:
            results.append({
                "id": account["id"], "name": account["name"], "score": round(best[0], 3),
                "match": best[2], "span": sorted(best[1]), "clitic": clitic if best[2] == "exact" else "",
            })

    results.sort(key=lambda r: r["score"], reverse=True)
    return results[:6]


# Question words that end sentences but are never names.
_TRAILING_STOP = {"دفعها", "دفعه", "تمت", "معامله", "حركه", "عندي", "حاليا", "اليوم", "الحد", "السقف", "قرب",
                  "بالدين", "واتساب", "واتس", "حسابه", "دفع", "سدد", "امس", "لحاله", "مره", "عليه",
                  "تجاوز", "تجاوزوا", "تعدى", "تخطى", "سقف", "الدين", "الديون", "دين", "ديون", "المسموح",
                  "الائتماني", "المديونين", "المدينين", "متاخر", "المتاخره", "زبائن", "الزبائن", "العملاء"}

_NAME_MARKERS = ("على", "من", "ل", "لـ", "عند", "حساب", "لحساب", "باسم", "اسمه", "المورد", "لمورد", "للمورد",
                 "الزبون", "للزبون", "لموزع", "موزع", "جديد", "لشركه", "شركه", "مع", "عن")


def guess_name(toks: list[Tok], skip: set[int], leading: bool = False) -> str | None:
    """Best-effort free-text name when no known account matched (e.g. to offer creating it)."""

    def collect(start: int) -> str | None:
        words = []
        for k in range(start, min(start + 3, len(toks))):
            if k in skip or toks[k].norm in NAME_STOPWORDS or _numeric(toks[k]) is not None or _is_phone(toks[k]):
                break
            words.append(toks[k].raw)
        return " ".join(words) or None

    for i, tok in enumerate(toks):
        if tok.norm in _NAME_MARKERS or tok.raw in _NAME_MARKERS:
            if name := collect(i + 1):
                return name
        # glued preposition: "لمصطفى", "لسمير"
        for clitic in ("ل",):
            if tok.norm.startswith(clitic) and len(tok.norm) > 3 and i not in skip and tok.norm not in NAME_STOPWORDS \
                    and _numeric(tok) is None and i > 0:
                stripped = tok.raw[1:] if tok.raw.startswith(clitic) else tok.raw
                rest = collect(i + 1)
                return stripped + (" " + rest if rest else "")

    if leading and toks and toks[0].norm not in NAME_STOPWORDS and _numeric(toks[0]) is None:
        return collect(0)

    # Last resort for questions: names usually close the sentence ("شو آخر دفعة دفعها خليل").
    words = []
    for k in range(len(toks) - 1, -1, -1):
        t = toks[k]
        if k in skip or t.norm in NAME_STOPWORDS or t.norm in _TRAILING_STOP or _numeric(t) is not None or len(words) == 3:
            break
        words.insert(0, t.raw)
    return " ".join(words) or None


# ------------------------------------------------------------------------ categories

CATEGORY_ROLES = {
    "customer": {"عميل", "عملاء", "زبون", "زباين", "زبائن", "الزباين", "الزبائن", "العملاء", "العميل", "الزبون"},
    "supplier": {"مورد", "موردين", "الموردين", "المورد", "موزع", "موزعين", "للموردين", "لمورد"},
}


def extract_category(toks: list[Tok], categories: list[dict]) -> tuple[dict | None, str | None]:
    words = set().union(*(t.variants for t in toks)) if toks else set()

    # A custom category mentioned by name wins.
    for c in categories:
        name = normalize(c["name"])
        if name and (name in words or strip_prefix(name) in words):
            return c, None

    for role, aliases in CATEGORY_ROLES.items():
        if words & aliases:
            keys = {"customer": ("عملاء", "زبائن", "زباين"), "supplier": ("مورد",)}[role]
            match = next((c for c in categories if any(k in normalize(c["name"]) for k in keys)), None)
            return match, role
    return None, None
