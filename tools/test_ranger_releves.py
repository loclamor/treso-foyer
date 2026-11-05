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


if __name__ == "__main__":
    unittest.main()
