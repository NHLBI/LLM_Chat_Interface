import importlib
import os
import tempfile
from unittest import TestCase, mock


class ParserMultiTests(TestCase):
    def setUp(self):
        self.module = importlib.reload(importlib.import_module('parser_multi'))

    def test_parse_doc_requires_existing_file(self):
        with self.assertRaises(ValueError):
            self.module.parse_doc('does-not-exist.txt', 'does-not-exist.txt')

    def test_parse_doc_rejects_empty_file(self):
        with tempfile.NamedTemporaryFile('wb', delete=False) as tmp:
            path = tmp.name
        try:
            with self.assertRaises(ValueError):
                self.module.parse_doc(path, 'empty.txt')
        finally:
            os.unlink(path)

    def test_parse_doc_routes_by_extension(self):
        with tempfile.NamedTemporaryFile('w', suffix='.pdf', delete=False) as tmp:
            tmp.write('dummy pdf content')
            tmp.flush()
            path = tmp.name
        try:
            with mock.patch.object(self.module, 'parse_pdf', return_value='PDF_OK') as mock_pdf:
                result = self.module.parse_doc(path, 'sample.pdf')
            mock_pdf.assert_called_once_with(path)
            self.assertEqual('PDF_OK', result)
        finally:
            os.unlink(path)

    def test_parse_doc_rejects_unknown_extension(self):
        with tempfile.NamedTemporaryFile('w', suffix='.bin', delete=False) as tmp:
            tmp.write('data')
            tmp.flush()
            path = tmp.name
        try:
            with self.assertRaises(ValueError):
                self.module.parse_doc(path, 'artifact.bin')
        finally:
            os.unlink(path)
