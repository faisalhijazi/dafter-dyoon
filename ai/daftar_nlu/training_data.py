"""Synthetic training corpus: dialect templates expanded with names, amounts and currencies.

{name}  -> an account name (usually the NAME placeholder the pipeline substitutes for a
           matched account, sometimes a raw name so unknown names are handled too)
{amount}, {cur}, {note}, {days}, {n}, {phone}, {cat}
"""

import random

from .text import NAME

INTENT_TEMPLATES: dict[str, list[str]] = {
    "add_debit": [
        "سجل {amount} {cur} على {name} {note}",
        "سجل على {name} {amount} {cur}",
        "قيد {amount} {cur} على {name} {note}",
        "قيد على {name} {amount}",
        "حط على {name} {amount} {cur}",
        "حط {amount} على حساب {name}",
        "اكتب على {name} {amount} {cur} {note}",
        "{name} اخذ بضاعة ب {amount} {cur}",
        "{name} اخد {amount} {cur} دين",
        "{name} عليه {amount} {cur}",
        "زيد على {name} {amount} {cur}",
        "ضيف على حساب {name} {amount} {cur} {note}",
        "دين جديد على {name} {amount}",
        "استلم {name} بضاعة ب {amount} {cur} على الحساب",
        "اعطيت {name} {amount} {cur} دين",
        "سلف {name} {amount} {cur}",
        "{name} سحب بضاعة بقيمة {amount}",
        "سجل دين {amount} {cur} على الزبون {name}",
        "قيد مدين على {name} بمبلغ {amount} {cur}",
        "اضف دين على {name} مبلغ {amount}",
        "على {name} {amount} {cur} {note}",
        "بعت ل {name} بالدين {amount} {cur}",
        "{name} اشترى بالدين ب {amount} {cur}",
        "خلي على {name} {amount} {cur}",
    ],
    "add_credit": [
        "قبضت {amount} {cur} من {name} {note}",
        "قبضت من {name} {amount}",
        "استلمت من {name} {amount} {cur}",
        "سدد {name} {amount} {cur} تحت الحساب",
        "{name} سدد {amount} {cur}",
        "{name} دفع {amount} {cur} {note}",
        "{name} دفعلي {amount} {cur}",
        "{name} جاب {amount} {cur}",
        "وصلني من {name} {amount} {cur}",
        "دفعة من {name} {amount} {cur}",
        "سجل دفعة ل {name} {amount} {cur}",
        "سجل ل {name} {amount} {cur}",
        "نزل عن {name} {amount} {cur}",
        "اخصم من حساب {name} {amount} {cur} دفعة",
        "{name} ورد {amount} {cur}",
        "قيد دائن ل {name} بمبلغ {amount} {cur}",
        "{name} رجع {amount} {cur} من الدين",
        "تسديد من {name} {amount}",
        "استلمت دفعة {amount} {cur} من الزبون {name}",
        "{name} له {amount} {cur}",
        "حط ل {name} {amount} {cur}",
        "قبض {amount} من {name}",
    ],
    "add_supplier_invoice": [
        "وصلت بضاعة من {name} ب {amount} {cur} دين",
        "وصلتني بضاعة من {name} {amount} {cur}",
        "استلمت بضاعة من المورد {name} بقيمة {amount} {cur}",
        "قيد علي ل {name} {amount} {cur}",
        "قيد علي لموزع {name} {amount}",
        "فاتورة مشتريات من {name} {amount} {cur}",
        "فاتورة جديدة من {name} ب {amount} {cur}",
        "صار علي ل {name} {amount} {cur}",
        "اشتريت من {name} بضاعة دين {amount} {cur}",
        "اخذت بضاعة من {name} على الحساب {amount} {cur}",
        "سجل فاتورة المورد {name} {amount} {cur}",
        "المورد {name} ورد بضاعة ب {amount} {cur}",
        "نزلت عندي بضاعة من {name} ب {amount}",
        "دين علي للمورد {name} {amount} {cur}",
        "طلبية من {name} ب {amount} {cur} لسا ما دفعت",
        "سجل علينا ل {name} {amount} {cur} {note}",
    ],
    "pay_supplier": [
        "دفعت ل {name} {amount} {cur}",
        "دفعت لمورد {name} {amount} {cur}",
        "دفعت للمورد {name} {amount}",
        "سددت فاتورة {name} {amount} {cur}",
        "سددت ل {name} {amount} {cur} اليوم",
        "اعطيت المورد {name} {amount} {cur}",
        "حولت ل {name} {amount} {cur}",
        "سددت للموزع {name} {amount} {cur}",
        "دفعة للمورد {name} {amount} {cur}",
        "خلصت ل {name} {amount} {cur} من الفاتورة",
        "دفعنا لشركة {name} {amount} {cur}",
        "بعتت ل {name} {amount} {cur} حوالة",
        "سديت {name} {amount} {cur}",
        "تسديد للمورد {name} مبلغ {amount}",
    ],
    "query_balance": [
        "كم باقي على {name}",
        "كم على {name}",
        "كم عليه {name}",
        "شو حساب {name} عندي",
        "شو حساب {name}",
        "قديش على {name}",
        "قديش حساب {name}",
        "رصيد {name}",
        "كم رصيد {name}",
        "اعرض حساب {name}",
        "وين وصل حساب {name}",
        "كم ل {name} عندي",
        "كم الي عند {name}",
        "{name} كم عليه",
        "{name} شو وضعه",
        "ما هو رصيد {name}",
        "بدي اعرف حساب {name}",
        "افتحلي حساب {name}",
        "كم دين {name}",
        "شو باقي على {name}",
    ],
    "query_last_transaction": [
        "شو اخر دفعة دفعها {name}",
        "متى اخر معاملة مع {name}",
        "متى اخر مرة دفع {name}",
        "اخر حركة ل {name}",
        "اخر عملية على حساب {name}",
        "شو اخر اشي سجلته ل {name}",
        "امتى {name} سدد اخر مرة",
        "اخر دفعة من {name}",
        "متى تعاملت مع {name} اخر مرة",
        "اعرض اخر حركات {name}",
        "شو اخر معاملات {name}",
        "اخر قيد على {name}",
    ],
    "query_overdue": [
        "اعرضلي الديون المتاخرة اكثر من شهر",
        "مين ما سدد من {days} يوم",
        "الديون المتاخرة",
        "مين متاخر بالدفع",
        "مين الزبائن اللي ما دفعوا من شهرين",
        "الحسابات المتاخرة اكثر من {days} يوم",
        "مين ما دفع من اسبوع",
        "مين ما سدد من زمان",
        "اعطيني قائمة المتاخرين عن السداد",
        "ديون ما انسددت من {days} يوم",
        "مين الزباين المتعثرين",
        "مين ما جاب مصاري من شهر",
        "المتاخرين بالسداد من العملاء",
        "مين عليه ديون قديمة",
    ],
    "query_top_debtors": [
        "مين اكثر {n} زبائن عليهم ديون",
        "مين اكبر المديونين عندي",
        "اكبر {n} مديونين",
        "رتبلي الزبائن حسب الدين",
        "مين عليه اكثر اشي",
        "اعلى الديون",
        "مين اكبر زبون عليه دين",
        "اكثر الناس عليهم مصاري",
        "توب {n} مديونين",
        "اعرض اكبر {n} ديون",
        "مين اكتر واحد عليه",
        "قائمة اكبر المدينين",
    ],
    "query_threshold_alert": [
        "مين الزبائن اللي تجاوزوا سقف الدين",
        "هل حساب {name} قرب على الحد",
        "مين تعدى السقف",
        "مين قرب على السقف",
        "هل {name} تجاوز السقف",
        "الحسابات اللي تجاوزت الحد الائتماني",
        "تنبيهات السقوف",
        "مين وصل الحد المسموح",
        "هل {name} فات الحد",
        "كم باقي ل {name} عالسقف",
        "مين تخطى سقف الدين",
        "في حد تجاوز السقف",
    ],
    "query_totals": [
        "كم اجمالي الديون اللي الي بالسوق",
        "كم الصافي اللي علي للموردين",
        "كم لي بالسوق",
        "كم مجموع الديون",
        "كم علي للناس",
        "كم الصافي",
        "شو الموقف المالي",
        "اجمالي لك وعليك",
        "كم مجموع اللي الي",
        "كم مجموع اللي علي",
        "الوضع المالي العام",
        "كم الديون كلها",
        "كم مطلوب مني للموردين",
        "كم الي عند الزبائن",
        "اعطيني ملخص الحسابات",
        "كم اجمالي الديون على العملاء",
    ],
    "query_currency_balance": [
        "كم حساب {name} ب{cur} لحاله",
        "كم {cur} علي حاليا",
        "كم {cur} الي بالسوق",
        "رصيد {name} ب{cur}",
        "كم على {name} ب{cur}",
        "حساب {name} بعملة {cur}",
        "كم مجموع ال{cur} عند الزبائن",
        "كم عندي ديون ب{cur}",
        "كم {name} عليه {cur}",
        "اعرض رصيد ال{cur} ل {name}",
    ],
    "query_exchange_rate": [
        "كم سعر صرف {cur} اليوم",
        "شو سعر {cur} مقابل الشيكل",
        "سعر {cur}",
        "كم سعر ال{cur}",
        "قديش ال{cur} اليوم",
        "كم بيسوى ال{cur}",
        "شو سعر الصرف",
        "اسعار الصرف",
        "كم صرف {cur}",
        "ال{cur} بكم",
        "سعر الصرف لل{cur}",
    ],
    "create_account": [
        "ضيف عميل جديد باسم {raw} جوال {phone}",
        "ضيف زبون جديد {raw}",
        "افتح حساب جديد ل {raw}",
        "افتح حساب باسم {raw} رقمه {phone}",
        "اضف عميل {raw}",
        "اضافة زبون جديد اسمه {raw}",
        "سجل زبون جديد {raw} {phone}",
        "حساب جديد {raw}",
        "ضيف مورد جديد {raw}",
        "افتح حساب مورد باسم {raw}",
        "ضيف {raw} على العملاء",
        "اعمل حساب ل {raw} رقم جواله {phone}",
        "انشئ حساب جديد باسم {raw}",
    ],
    "trigger_whatsapp": [
        "جهز رسالة واتساب لحساب {name}",
        "ابعت كشف حساب ل {name}",
        "ارسل ل {name} على الواتس",
        "ذكر {name} بالدين",
        "طالب {name} بالدين",
        "ابعث ل {name} رسالة مطالبة",
        "شارك حساب {name} واتساب",
        "رسالة واتس ل {name}",
        "ابعتله كشف ل {name}",
        "بدي ابعت ل {name} حسابه",
        "ارسل كشف حساب {name}",
        "واتساب {name}",
    ],
    "greeting": [
        "مرحبا", "السلام عليكم", "هلا", "اهلا", "صباح الخير", "مساء الخير",
        "كيفك", "هاي", "مرحبا كيف الحال", "يعطيك العافية", "شكرا", "مشكور", "تسلم",
    ],
    # Negative class: anything outside bookkeeping, so the model can say "I don't know" instead of guessing.
    "out_of_scope": [
        "شو الطقس اليوم", "اكتبلي قصيده", "احكيلي نكته", "مين ربح المباراه", "شو اخبار الدوري",
        "ترجملي هالجمله", "كيف اطبخ مقلوبه", "شو عاصمه فرنسا", "اعطيني وصفه كيك", "كم الساعه",
        "شغلي اغنيه", "مين رئيس امريكا", "احسبلي الجذر التربيعي", "اكتب ايميل لصاحبي", "شو معنى الحياه",
        "بدي العب لعبه", "وين اقرب مطعم", "كم عمرك", "انت مين", "اكتبلي مقال عن التسويق",
        "حجزلي تذكره طياره", "شو رايك بالسياسه", "كيف اتعلم برمجه", "ذكرني اشرب مي", "صورلي قطه",
    ],
    "help": [
        "شو بتقدر تعمل", "ساعدني", "مساعدة", "شو الاوامر", "كيف استخدمك",
        "شو بتعرف تعمل", "اعطيني امثلة", "help", "شو الخدمات", "كيف بشتغل",
    ],
}

RAW_NAMES = [
    "ابو احمد", "محمد", "سائد", "رامي", "خليل", "ابراهيم", "كمال", "مصطفى", "سمير",
    "ابو محمود", "ام علي", "شركه القدس", "موزع الالبان", "مورد الزيوت", "محل النور",
    "احمد الخطيب", "علي حسن", "يوسف", "فادي", "ابو خالد", "شركه الامل", "سوبرماركت الريم",
]
AMOUNTS = ["150", "50", "400", "100", "1200", "300", "500", "200", "75", "2500", "1,500", "35.5", "ميه", "الف", "ميتين", "3 الاف"]
CURRENCIES = ["شيكل", "شواكل", "دولار", "دينار", "ريال", "جنيه", "$", "دينار اردني", "", "", ""]
CUR_NAMES = ["دولار", "دينار", "شيكل", "ريال", "جنيه", "يورو", "درهم"]
NOTES = ["بضاعه", "قطع غيار", "دفعه", "تحت الحساب", "خضار", "اغراض البيت", "", "", "", "فاتوره", "مواد بناء"]
DAYS = ["45", "30", "60", "90", "15", "20"]
NS = ["3", "5", "10", "7", ""]
PHONES = ["0599000000", "0598123456", "+970599111222", "0567000111", ""]


def _fill(template: str, rng: random.Random) -> str:
    name = NAME if rng.random() < 0.7 else rng.choice(RAW_NAMES)
    values = {
        "name": name,
        "raw": rng.choice(RAW_NAMES),
        "amount": rng.choice(AMOUNTS),
        "cur": rng.choice(CUR_NAMES) if "{cur}" in template and template.count("{amount}") == 0 else rng.choice(CURRENCIES),
        "note": rng.choice(NOTES),
        "days": rng.choice(DAYS),
        "n": rng.choice(NS),
        "phone": rng.choice(PHONES),
    }
    return " ".join(template.format(**values).split())


def build_samples(per_template: int = 12, seed: int = 7) -> list[tuple[str, str]]:
    rng = random.Random(seed)
    samples = []

    for intent, templates in INTENT_TEMPLATES.items():
        for template in templates:
            # Fixed phrases (greetings, help) are repeated so their classes aren't drowned out.
            repeats = 4 if "{" not in template else per_template
            samples.extend((_fill(template, rng), intent) for _ in range(repeats))

    rng.shuffle(samples)
    return samples
