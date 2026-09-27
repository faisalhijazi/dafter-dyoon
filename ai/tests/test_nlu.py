"""Run:  python -m unittest discover -s ai/tests"""

import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

from assistant import load_model, train  # noqa: E402
from daftar_nlu.acceptance import CONTEXT, evaluate  # noqa: E402
from daftar_nlu.pipeline import parse  # noqa: E402


class NluAcceptanceTest(unittest.TestCase):
    model = load_model()

    def test_merchant_phrases(self):
        result = evaluate(self.model)
        self.assertEqual([], result["failures"], json.dumps(result["failures"], ensure_ascii=False, indent=1))


class LearningTest(unittest.TestCase):
    model = load_model()

    def test_masked_text_hides_names_numbers_and_phones(self):
        masked = parse("سجل 150 شيكل على أبو أحمد بضاعة", CONTEXT, self.model)["masked_text"]
        self.assertEqual("سجل xnum شيكل علي xname بضاعه", masked)

        unknown_name = parse("سجل على محمود الخطيب 100", CONTEXT, self.model)["masked_text"]
        self.assertNotIn("محمود", unknown_name)

        phone = parse("ضيف عميل جديد باسم سمير جوال 0599000000", CONTEXT, self.model)["masked_text"]
        self.assertNotIn("0599000000", phone)
        self.assertIn("xphone", phone)

    def test_personal_phrase_overrides_the_model(self):
        text = "نزل على رامي 40"
        taught = parse(text, CONTEXT, self.model)["masked_text"]
        result = parse(text, {**CONTEXT, "phrases": [{"text": taught, "intent": "add_debit"}]}, self.model)
        self.assertEqual(("add_debit", "personal"), (result["intent"], result["source"]))
        self.assertEqual(4, result["entities"]["account"]["id"])

    def test_forced_intent_from_what_did_you_mean(self):
        result = parse("رامي 40", {**CONTEXT, "force_intent": "add_credit"}, self.model)
        self.assertEqual(("add_credit", "forced"), (result["intent"], result["source"]))
        self.assertEqual(40, result["entities"]["amount"])

    def test_learned_phrases_teach_the_model_and_report_the_gate(self):
        with tempfile.TemporaryDirectory() as tmp:
            extra = os.path.join(tmp, "learned.json")
            out = os.path.join(tmp, "model.json")
            report = os.path.join(tmp, "report.json")
            with open(extra, "w", encoding="utf-8") as fh:
                json.dump([{"text": "كتبت ع xname xnum", "intent": "add_debit"}] * 3, fh, ensure_ascii=False)

            model = train(extra, out, report, verbose=False)

            with open(report, encoding="utf-8") as fh:
                data = json.load(fh)
            self.assertEqual(3, data["learned"])
            self.assertEqual(data["acceptance_total"], data["acceptance_passed"])
            self.assertEqual("add_debit", parse("كتبت ع رامي 20", CONTEXT, model)["intent"])


class SmallTalkTest(unittest.TestCase):
    model = load_model()
    context = {**CONTEXT, "smalltalk": [
        {"key": "salam", "patterns": ["السلام عليكم", "سلام"]},
        {"key": "how_are_you", "patterns": ["كيف حالك", "كيفك"]},
        {"key": "morning", "patterns": ["صباح الخير"]},
        {"key": "pricing", "patterns": ["كم سعر الاشتراك"]},
        {"key": "thanks", "patterns": ["شكرا"]},
    ]}

    def parse(self, text):
        return parse(text, self.context, self.model)

    def test_whole_message_greetings_become_smalltalk(self):
        for text, keys in [("صباح الخير", ["morning"]), ("السلام عليكم كيف حالك؟", ["salam", "how_are_you"]),
                           ("شكرا يا صاحبي", ["thanks"]), ("كم سعر الاشتراك", ["pricing"])]:
            result = self.parse(text)
            self.assertEqual("smalltalk", result["intent"], text)
            self.assertEqual(keys, result["smalltalk"]["keys"], text)

    def test_greeting_before_a_command_keeps_the_command(self):
        result = self.parse("السلام عليكم، كم باقي على رامي؟")
        self.assertEqual("query_balance", result["intent"])
        self.assertEqual(["salam"], result["smalltalk"]["keys"])

    def test_bookkeeping_questions_are_not_small_talk(self):
        self.assertEqual("query_exchange_rate", self.parse("كم سعر الدولار")["intent"])
        self.assertIsNone(self.parse("سجل 50 شيكل على رامي")["smalltalk"])


if __name__ == "__main__":
    unittest.main()
