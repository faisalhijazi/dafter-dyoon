"""Acceptance phrases (the merchants' own wording). Used by the unit tests AND as the quality gate
that every retrained model must pass before it is activated."""

from .pipeline import parse

CONTEXT = {
    "accounts": [
        {"id": 1, "name": "أبو أحمد"}, {"id": 2, "name": "سائد"}, {"id": 3, "name": "محمد"},
        {"id": 4, "name": "رامي"}, {"id": 5, "name": "شركة القدس"}, {"id": 6, "name": "موزع الألبان"},
        {"id": 7, "name": "مورد الزيوت"}, {"id": 8, "name": "خليل"}, {"id": 9, "name": "إبراهيم"},
        {"id": 10, "name": "كمال"}, {"id": 11, "name": "مصطفى"}, {"id": 12, "name": "شركة النور"},
        {"id": 13, "name": "علي حسن"},
    ],
    "currencies": [
        {"id": 1, "code": "شيكل", "name": "شيكل إسرائيلي", "is_base": True},
        {"id": 2, "code": "دولار", "name": "دولار أمريكي", "is_base": False},
        {"id": 3, "code": "دينار", "name": "دينار أردني", "is_base": False},
        {"id": 4, "code": "سعودي", "name": "ريال سعودي", "is_base": False},
    ],
    "categories": [{"id": 1, "name": "العملاء"}, {"id": 2, "name": "الموردين"}],
}

# text -> expected intent and a subset of expected entities
CASES = [
    ("سجل 150 شيكل على أبو أحمد بضاعة", "add_debit", {"amount": 150, "currency_id": 1, "account": 1, "notes": "بضاعة"}),
    ("قيد 50 دولار على سائد قطع غيار", "add_debit", {"amount": 50, "currency_id": 2, "account": 2, "notes": "قطع غيار"}),
    ("قبضت 400 شيكل من محمد دفعة", "add_credit", {"amount": 400, "account": 3}),
    ("سدد رامي 100 دولار تحت الحساب", "add_credit", {"amount": 100, "currency_id": 2, "account": 4}),
    ("وصلت بضاعة من شركة القدس بـ 1200 شيكل دين", "add_supplier_invoice", {"amount": 1200, "account": 5}),
    ("قيد علي لموزع الألبان 300", "add_supplier_invoice", {"amount": 300, "account": 6}),
    ("دفعت لمورد الزيوت 500 شيكل", "pay_supplier", {"amount": 500, "account": 7}),
    ("سددت فاتورة الألبان اليوم 200 دينار", "pay_supplier", {"amount": 200, "currency_id": 3}),
    ("كم باقي على رامي؟", "query_balance", {"account": 4}),
    ("شو حساب شركة النور عندي؟", "query_balance", {"account": 12}),
    ("شو آخر دفعة دفعها خليل؟", "query_last_transaction", {"account": 8, "payments_only": True}),
    ("متى آخر معاملة تمت مع إبراهيم؟", "query_last_transaction", {"account": 9}),
    ("اعرضلي الديون المتأخرة أكثر من شهر", "query_overdue", {"days": 30}),
    ("مين ما سدد من 45 يوم؟", "query_overdue", {"days": 45}),
    ("مين أكثر 3 زبائن عليهم ديون؟", "query_top_debtors", {"limit": 3}),
    ("مين أكبر المديونين عندي؟", "query_top_debtors", {}),
    ("مين الزبائن اللي تجاوزوا سقف الدين؟", "query_threshold_alert", {"name_text": None}),
    ("مين تجاوز سقف الدين؟", "query_threshold_alert", {"name_text": None}),
    ("هل حساب كمال قرب على الحد؟", "query_threshold_alert", {"account": 10}),
    ("كم إجمالي الديون اللي لي بالسوق؟", "query_totals", {"totals_type": "receivable"}),
    ("كم الصافي اللي علي للموردين؟", "query_totals", {"totals_type": "net", "category_id": 2}),
    ("كم حساب محمد بالدولار لحاله؟", "query_currency_balance", {"account": 3, "currency_id": 2}),
    ("كم دينار أردني علي حالياً؟", "query_currency_balance", {"currency_id": 3}),
    ("كم سعر صرف الدولار اليوم؟", "query_exchange_rate", {"currency_id": 2}),
    ("شو سعر الدينار مقابل الشيكل؟", "query_exchange_rate", {"currency_id": 3}),
    ("ضيف عميل جديد باسم سمير جوال 0599000000", "create_account", {"name_text": "سمير", "phone": "0599000000"}),
    ("جهز رسالة واتساب لحساب أبو أحمد", "trigger_whatsapp", {"account": 1}),
    ("ابعت كشف حساب لمصطفى", "trigger_whatsapp", {"account": 11}),
    # extra phrasing not copied from the templates
    ("حط على كمال ميتين شيكل", "add_debit", {"amount": 200, "account": 10}),
    ("سجل على علي حسن 3 الاف", "add_debit", {"amount": 3000, "account": 13}),
    ("خليل دفع 1,500 شيكل", "add_credit", {"amount": 1500, "account": 8}),
    ("مرحبا", "greeting", {}),
]


def evaluate(model) -> dict:
    """Run every acceptance case; returns {passed, total, failures}."""
    failures = []
    for text, intent, expected in CASES:
        result = parse(text, CONTEXT, model)
        ents = result["entities"]
        got = {"intent": result["intent"], **{k: ents.get(k) for k in expected if k != "account"}}
        if "account" in expected:
            got["account"] = (ents.get("account") or {}).get("id")
        want = {"intent": intent, **expected}
        if got != want:
            failures.append({"text": text, "want": want, "got": got})
    return {"passed": len(CASES) - len(failures), "total": len(CASES), "failures": failures}
