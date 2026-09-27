# مساعد AI — local NLU engine

A small, dependency-free (Python 3.10+, standard library only) engine that turns a merchant's
Arabic command into an **intent + entities**. No external AI provider and no network calls:
the merchant's data never leaves the server.

```
Livewire chat page ─► App\Services\Assistant\PythonNlu ─► python ai/assistant.py parse (stdin/stdout JSON)
                   ◄─ App\Services\Assistant\Assistant (executes the intent with tenant-scoped models)
```

Python only *understands* text. All reads and writes happen in Laravel, so tenant isolation
stays in one place. Money movements are never written directly: the assistant replies with a
confirmation card and records only after «تأكيد».

## Pieces

| File | Role |
|---|---|
| `daftar_nlu/text.py` | Arabic normalisation (hamza/ta-marbuta/digits), features (words, stems, bigrams, char 3/4-grams) |
| `daftar_nlu/classifier.py` | Multinomial Naive Bayes, saved as `model.json` |
| `daftar_nlu/training_data.py` | Dialect templates per intent, expanded into ~2,300 samples (incl. an `out_of_scope` class) |
| `daftar_nlu/entities.py` | Amounts (`1,500`, `3 آلاف`, `ميتين`, `الف وخمسميه`), currencies, fuzzy account names, days, limits, phone, category |
| `daftar_nlu/pipeline.py` | Glue + light rules (off-topic detection, currency-query rerouting, notes cleanup) |
| `assistant.py` | CLI: `parse`, `train`, `try` |

Intents: `add_debit`, `add_credit`, `add_supplier_invoice`, `pay_supplier`, `query_balance`,
`query_last_transaction`, `query_overdue`, `query_top_debtors`, `query_threshold_alert`,
`query_totals`, `query_currency_balance`, `query_exchange_rate`, `create_account`,
`trigger_whatsapp`, plus `greeting`, `help`, `unknown`.

## Commands

```bash
python ai/assistant.py train                      # rebuild model.json (prints hold-out accuracy)
python ai/assistant.py try "سجل 150 شيكل على أبو أحمد"
python -m unittest discover -s ai/tests           # merchants' own phrasing, must stay green
```

## Teaching it new phrasing

Add sentences to `INTENT_TEMPLATES` in `daftar_nlu/training_data.py` (use `{name}`, `{amount}`,
`{cur}`, `{note}` …), run `train`, then the tests. Add a case to `tests/test_nlu.py` for anything
a merchant reported as misunderstood.

## Greetings, courtesies and general questions

All small talk lives in **`config/assistant_smalltalk.php`** (Laravel): each entry has the
`patterns` merchants type and several `replies` (one is picked at random; placeholders `{name}`,
`{store}`, `{app}`, `{time}`). Edits apply immediately — no retraining. Matching happens in
`daftar_nlu/smalltalk.py`: a message that is mostly small talk gets a small-talk reply; a greeting
in front of a command ("السلام عليكم، كم على رامي؟") gets the entry's short `prefix` before the answer.

## Self-learning

| Level | What is learnt | From | Scope | When |
|---|---|---|---|---|
| Nicknames | «عثمان» → «عثمان ظهير» (`account_aliases`) | merchant picks the account from «هل تقصد؟» | that store | immediately |
| Personal phrases | «نزّل على xname xnum» → add_debit (`assistant_personal_phrases`) | 👎 «لم يفهمني» + «ماذا قصدت؟» | that store | immediately |
| Shared model | masked phrase → intent | phrases used at ≥ `ASSISTANT_LEARN_MIN_TENANTS` (3) stores and never disputed/cancelled, or approved by the admin | all stores | nightly `assistant:learn` |

- Every handled sentence is stored in `assistant_samples` **privacy-masked** (`سجل xnum شيكل علي xname بضاعه`):
  no customer names, amounts or phones. Signals: `answered`, `confirmed` (tapped «تأكيد»),
  `corrected` («ماذا قصدت؟»), `unknown`; status `disputed` (👎) / `cancelled` («إلغاء») is excluded from learning.
- `assistant:learn` (scheduled daily at `ASSISTANT_LEARN_AT`, default 03:00) trains a candidate into
  `storage/app/assistant/models/`. It is activated only if it passes **every** acceptance phrase and its
  hold-out accuracy does not drop more than `ASSISTANT_LEARN_TOLERANCE`; otherwise it is rejected.
  The last 5 versions are kept for rollback.
- Admin page **«تعلّم المساعد»** (`/admin/assistant`): label sentences the assistant did not understand,
  approve / reject learnt phrases, retrain now, activate an older version or return to the shipped model.
- Merchants see and delete their store's memory from the 💡 button on the chat page.

The scheduler must be running in production: a cron entry `* * * * * php artisan schedule:run`
(or `php artisan schedule:work` locally).

## Deployment

Set `ASSISTANT_PYTHON` in `.env` to the interpreter's full path (web servers often lack Python
on their PATH), e.g. `/usr/bin/python3`. `model.json` is committed; if it is missing it is
retrained automatically on first use.
