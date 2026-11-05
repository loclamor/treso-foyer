"""Tests de tools/ranger_releves.py.

Lancement : python3 -I -m unittest discover -s tools -p 'test_*.py'
"""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

_spec = importlib.util.spec_from_file_location("ranger_releves", Path(__file__).with_name("ranger_releves.py"))
ranger = importlib.util.module_from_spec(_spec)
sys.modules["ranger_releves"] = ranger  # requis par @dataclass
_spec.loader.exec_module(ranger)


class CollectSourcesTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        self._saved = (ranger.ROOT, ranger.DATA)
        ranger.ROOT, ranger.DATA = self.root, self.root / "data"

    def tearDown(self):
        ranger.ROOT, ranger.DATA = self._saved
        self._tmp.cleanup()

    def _touch(self, rel):
        p = self.root / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text("x")
        return p

    def test_default_scans_only_inbox_never_project_code(self):
        inbox_file = self._touch("data/inbox/releve.pdf")
        self._touch("src/Kernel.php")
        self._touch("vendor/autoload.php")
        self._touch("Makefile")
        self._touch("releve_racine.pdf")

        self.assertEqual(ranger.collect_sources([]), [inbox_file])

    def test_default_without_inbox_scans_nothing(self):
        self._touch("src/Kernel.php")

        self.assertEqual(ranger.collect_sources([]), [])

    def test_explicit_source_is_still_honoured(self):
        f = self._touch("ailleurs/releve.pdf")

        self.assertEqual(ranger.collect_sources([str(self.root / "ailleurs")]), [f])


class ClassifyPdfTest(unittest.TestCase):
    """Données fictives : seul le texte extrait du PDF compte."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.pdf = Path(self._tmp.name) / "releve"
        self.pdf.write_bytes(b"%PDF-1.4 fictif")
        self._saved = ranger.pdf_text
        self.book = ranger.AccountBook.__new__(ranger.AccountBook)
        self.book.new = {}
        self.book.cfg = __import__("configparser").ConfigParser()
        self.book.cfg.add_section("comptes")

    def tearDown(self):
        ranger.pdf_text = self._saved
        self._tmp.cleanup()

    def test_closing_date_found_after_first_page(self):
        page1 = "Situation de vos comptes au 21 mars 2017\nCCP n° 12 345 67 X 999   + 10,00\n"
        rest = page1 + "Nouveau solde au 21/03/2017   10,00\n"
        ranger.pdf_text = lambda path, first_page_only=True: page1 if first_page_only else rest

        dest, _ = ranger.classify(self.pdf, self.book)

        self.assertEqual(dest, Path("releves", "compte-x999", "2017", "2017-03_releve.pdf"))

    def test_monthly_statement_with_annexed_fees_stays_monthly(self):
        page1 = "Situation de vos comptes au 21 janvier 2020\nCCP n° 12 345 67 X 999   + 10,00\n"
        rest = page1 + "Nouveau solde au 21/01/2020   10,00\nCompte des frais\nPériode Du 01/01/2019 au 31/12/2019\n"
        ranger.pdf_text = lambda path, first_page_only=True: page1 if first_page_only else rest

        dest, _ = ranger.classify(self.pdf, self.book)

        self.assertEqual(dest, Path("releves", "compte-x999", "2020", "2020-01_releve.pdf"))

if __name__ == "__main__":
    unittest.main()
