import importlib
import os
import tempfile
from unittest import TestCase, mock

import tests.python.stubs  # noqa: F401  ensures optional deps are stubbed before imports


class RagRetrieveTests(TestCase):
    def setUp(self):
        self.module = importlib.reload(importlib.import_module('inc.rag_retrieve'))

    def test_load_ini_sets_embedding_dim(self):
        with tempfile.NamedTemporaryFile('w', delete=False) as tmp:
            tmp.write('[azure-embedding]\napi_key = "KEY"\nurl = "https://example"\n'
                      'deployment_name = "model-large"\napi_version = "2024"\n')
            ini_path = tmp.name

        try:
            self.module.load_ini(ini_path)
            self.assertEqual('KEY', self.module.AZURE['key'])
            self.assertEqual(3072, self.module.EMBED_DIM)
        finally:
            os.unlink(ini_path)

    def test_embed_query_uses_azure(self):
        self.module.AZURE.update({'key': 'abc', 'endpoint': 'https://example', 'deployment': 'model', 'api_version': '2024-06-01'})
        self.module.OPENAI['key'] = ''

        class DummyResponse:
            def __init__(self):
                self._data = {'data': [{'index': 0, 'embedding': [0.1, 0.2]}]}

            def raise_for_status(self):
                pass

            def json(self):
                return self._data

        with mock.patch('requests.post', return_value=DummyResponse()) as mock_post:
            vector = self.module.embed_query('question')

        self.assertEqual([0.1, 0.2], vector)
        called_url = mock_post.call_args[0][0]
        self.assertIn('https://example', called_url)

    def test_assemble_snippet_deduplicates(self):
        self.module._token_len = lambda text: len(text)

        payload = {
            'document_id': 1,
            'chunk_index': 0,
            'filename': 'doc1.pdf',
            'page_range': '2',
            'chunk_text': 'First sentence. Second sentence mentions testing! Third sentence ends here.'
        }

        class Point:
            def __init__(self, payload):
                self.payload = payload

        points = [Point(payload), Point(payload)]
        snippet, used = self.module.assemble_snippet(points, 'testing tools', max_tokens=10_000)

        self.assertIn('doc1.pdf', snippet)
        self.assertEqual(1, snippet.count('Second sentence'))
        self.assertEqual(1, len(used))
        self.assertEqual(0, used[0]['chunk_index'])

    def test_assemble_snippet_fallback(self):
        self.module._token_len = lambda text: len(text)
        result, used = self.module.assemble_snippet([], 'question', max_tokens=10)
        self.assertIn('No highly relevant passages found.', result)
        self.assertEqual([], used)
