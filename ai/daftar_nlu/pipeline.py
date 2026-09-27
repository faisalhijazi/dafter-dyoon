"""parse(): free text + tenant context -> {intent, confidence, entities, masked_text}."""

from difflib import SequenceMatcher

from .classifier import IntentClassifier
from .entities import (
    NAME_STOPWORDS, _is_phone, _numeric, extract_amount, extract_category, extract_currency,
    extract_days, extract_limit, extract_phone, guess_name, match_accounts, tokenize,
)
from . import smalltalk
from .text import NAME, mask_numbers
from .training_data import INTENT_TEMPLATES

WRITE_INTENTS = {"add_debit", "add_credit", "add_supplier_invoice", "pay_supplier"}
MIN_CONFIDENCE = 0.35
MIN_COVERAGE = 0.5
# A merchant's personal phrase applies when their masked sentence is this close to it.
PERSONAL_SIMILARITY = 0.9
# Small talk wins when it covers at least this share of the meaningful words.
SMALLTALK_COVERAGE = 0.6
INTENTS = set(INTENT_TEMPLATES) - {"out_of_scope"}

_PAYMENT_WORDS = {"دفعه", "دفع", "دفعها", "سدد", "تسديد", "سدادها", "قبضت", "سداد", "دفعات"}
_NET_WORDS = {"صافي", "الصافي"}
_PAYABLE_WORDS = {"علي", "عليا", "علينا", "مني", "للموردين", "مطلوب"}
_RECEIVABLE_WORDS = {"لي", "الي", "لنا", "بالسوق", "السوق", "للزبائن", "الزبائن", "العملاء", "لك"}
# Words that are meaningful notes even though they are stopwords for names.
_NOTE_WORDS = {"بضاعه", "دفعه", "فاتوره", "تحت", "الحساب"}
_NOTE_STOPWORDS = (NAME_STOPWORDS - _NOTE_WORDS) | {"اليوم", "امس", "هلا", "الان", "حاليا", "يا", "بس", "لو", "سمحت", "بقيمه",
                                    "مبلغ", "بمبلغ", "قيمه", "اردني", "امريكي", "سعودي", "مصري", "يمني"}
_CREATE_MARKERS = {"باسم", "اسمه", "اسمها", "جديد", "جديده"}


def _notes(toks, used: set[int], name_span: set[int]) -> str | None:
    """Free text after the last structural entity (amount / currency / name), e.g. "بضاعة", "تحت الحساب"."""
    anchors = used | name_span
    if not anchors:
        return None
    words = [t.raw for i, t in enumerate(toks)
             if i > max(anchors) and i not in anchors and t.norm not in _NOTE_STOPWORDS and not _is_phone(t)]
    # Drop a leading preposition left over ("من", "على") so notes read naturally.
    while words and words[0] in ("من", "على", "ل", "في", "عن"):
        words.pop(0)
    note = " ".join(words).strip()
    return note or None


def _create_name(toks, skip: set[int]) -> str | None:
    for i, tok in enumerate(toks):
        if tok.norm in _CREATE_MARKERS or tok.raw in _CREATE_MARKERS:
            words = []
            for k in range(i + 1, min(i + 5, len(toks))):
                t = toks[k]
                if k in skip or _is_phone(t) or _numeric(t) is not None or t.norm in {"جوال", "رقم", "رقمه", "هاتف", "موبايل", "تلفون", "على", "في", "باسم", "اسمه"}:
                    break
                if t.norm in {"عميل", "زبون", "مورد"} and not words:
                    continue
                words.append(t.raw)
            if words:
                return " ".join(words)
    return guess_name(toks, skip)


def _classification_text(toks, name_span: set[int], clitic: str = "") -> str:
    out, previous_masked = [], False
    for i, tok in enumerate(toks):
        if i in name_span:
            if not previous_masked:
                # Keep a glued preposition ("لموزع" -> "ل xname"): it separates "علي ل…" from "على …".
                out.extend([clitic[:1], NAME] if clitic else [NAME])
            previous_masked = True
        else:
            out.append(tok.raw)
            previous_masked = False
    return " ".join(out)


def masked_text(toks, name_span: set[int], name_text: str | None, phones: set[int]) -> str:
    """Privacy-safe form of the sentence used for learning: names, numbers and phones replaced.

    "سجل 150 شيكل على أبو أحمد بضاعة" -> "سجل xnum شيكل علي xname بضاعه"
    """
    guessed = set((name_text or "").split())
    out, previous_masked = [], False
    for i, tok in enumerate(toks):
        if i in name_span or tok.raw in guessed:
            if not previous_masked:
                out.append(NAME)
            previous_masked = True
            continue
        previous_masked = False
        out.append("xphone" if i in phones else tok.norm)
    return " ".join(mask_numbers(" ".join(out)).split())


def _personal_intent(masked: str, phrases: list[dict]) -> str | None:
    """Intent from the merchant's own taught phrases (exact or near-exact masked match)."""
    best, best_ratio = None, 0.0
    for phrase in phrases:
        ratio = 1.0 if phrase.get("text") == masked else SequenceMatcher(None, masked, phrase.get("text", "")).ratio()
        if ratio > best_ratio and phrase.get("intent") in INTENTS:
            best, best_ratio = phrase["intent"], ratio
    return best if best_ratio >= PERSONAL_SIMILARITY else None


def parse(text: str, context: dict, model: IntentClassifier) -> dict:
    accounts = context.get("accounts", [])
    currencies = context.get("currencies", [])
    categories = context.get("categories", [])

    toks = tokenize(text)
    phones = {i for i, t in enumerate(toks) if _is_phone(t)}

    currency, cur_span = extract_currency(toks, currencies)
    days, days_span = extract_days(toks)
    limit, limit_span = extract_limit(toks)
    amount, amount_span = extract_amount(toks, skip=days_span | limit_span | phones)

    structural = cur_span | amount_span | days_span | limit_span | phones
    candidates = match_accounts(toks, accounts, skip=structural)
    best = candidates[0] if candidates else None
    name_span = set(best["span"]) if best and best["score"] >= 0.6 else set()

    classification_text = _classification_text(toks, name_span, best.get("clitic", "") if name_span else "")
    ranked = model.predict(classification_text)
    intent, confidence = ranked[0]
    words = set().union(*(t.variants for t in toks)) if toks else set()

    # --- light rules on top of the statistical model -----------------------------------------
    base_id = next((c["id"] for c in currencies if c.get("is_base")), None)
    if intent == "query_balance" and currency and currency["id"] != base_id:
        intent = "query_currency_balance"
    if "سعر" in words and currency and amount is None and intent not in WRITE_INTENTS:
        intent = "query_exchange_rate"
    if intent in WRITE_INTENTS and amount is None and ranked[1:] and ranked[1][0] not in WRITE_INTENTS \
            and ranked[1][1] > 0.2:
        intent, confidence = ranked[1]
    if intent == "query_currency_balance" and not currency:
        intent = "query_balance"
    # Cues an intent cannot do without; missing cues usually mean off-topic text.
    if intent == "query_exchange_rate" and not (currency or words & {"سعر", "صرف", "اسعار", "الصرف"}):
        intent = "unknown"
    if intent == "out_of_scope" or confidence < MIN_CONFIDENCE or model.coverage(classification_text) < MIN_COVERAGE:
        intent = "unknown"

    # --- learning: the merchant's own phrases, or an explicit "ماذا قصدت؟" answer, win ------------
    masked = masked_text(toks, name_span, guess_name(toks, structural) if not name_span else None, phones)
    source = "model"
    if (personal := _personal_intent(masked, context.get("phrases", []))) is not None:
        intent, confidence, source = personal, 0.99, "personal"
    if context.get("force_intent") in INTENTS:
        intent, confidence, source = context["force_intent"], 1.0, "forced"

    # Greetings / courtesies / general questions: answer them when they are the whole message,
    # or when nothing else was understood. A greeting before a command ("السلام عليكم، كم على
    # رامي؟") keeps the command intent; Laravel prefixes the greeting's reply.
    talk = smalltalk.match(toks, context.get("smalltalk", []))
    if talk and source == "model" and (talk["coverage"] >= SMALLTALK_COVERAGE or intent in {"greeting", "help", "unknown"}):
        intent, confidence, source = "smalltalk", 1.0, "smalltalk"

    category, role = extract_category(toks, categories)

    entities = {
        "amount": amount,
        "currency_id": currency["id"] if currency else None,
        "account": {k: best[k] for k in ("id", "name", "score", "match")} if best else None,
        "account_candidates": [{k: c[k] for k in ("id", "name", "score", "match")} for c in candidates],
        "name_text": guess_name(toks, structural, leading=intent in WRITE_INTENTS) if not best or best["match"] != "exact" else None,
        "notes": _notes(toks, cur_span | amount_span, name_span) if intent in WRITE_INTENTS else None,
        "days": days,
        "limit": limit,
        "phone": extract_phone(text) if phones else None,
        "category_id": category["id"] if category else None,
        "category_role": role,
        "totals_type": (
            "net" if words & _NET_WORDS
            else "payable" if words & _PAYABLE_WORDS
            else "receivable" if words & _RECEIVABLE_WORDS
            else "all"
        ),
        "payments_only": bool(words & _PAYMENT_WORDS),
    }

    # An unknown name must not leak into the notes ("على حمدي الجنجي بضاعة" -> "بضاعة").
    if entities["notes"] and entities["name_text"] and entities["notes"].startswith(entities["name_text"]):
        entities["notes"] = entities["notes"][len(entities["name_text"]):].strip() or None

    if intent == "create_account":
        entities["name_text"] = _create_name(toks, structural)
        entities["account"] = None

    return {
        "intent": intent,
        "confidence": round(confidence, 3),
        "source": source,
        "alternatives": [{"intent": i, "confidence": round(p, 3)} for i, p in ranked[1:]],
        "masked_text": masked,
        "smalltalk": talk,
        "entities": entities,
    }
