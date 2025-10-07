import importlib
import os
import tempfile
from types import SimpleNamespace
from unittest import TestCase, mock

import tests.python.stubs  # noqa: F401  ensures optional deps are stubbed before imports


class BuildIndexTests(TestCase):
    def setUp(self):
        self.module = importlib.reload(importlib.import_module('inc.build_index'))

    def test_chunk_text_overlap(self):
        class DummyEncoding:
            def encode(self, text):
                return list(text)

            def decode(self, tokens):
                return ''.join(tokens)

        with mock.patch.object(self.module, 'token_encoder', return_value=DummyEncoding()):
            chunks = self.module.chunk_text('abcdefgh', max_tokens=3, overlap=1)

        self.assertEqual(['abc', 'cde', 'efg', 'gh'], chunks)

    def test_load_ini_sets_azure(self):
        with tempfile.NamedTemporaryFile('w', delete=False) as tmp:
            tmp.write('[azure-embedding]\napi_key = "KEY"\nurl = "https://example"\n'
                      'deployment_name = "model-large"\napi_version = "2024-06-01"\n')
            ini_path = tmp.name

        try:
            self.module.load_ini(ini_path)
            self.assertEqual('KEY', self.module.AZURE['key'])
            self.assertEqual('https://example', self.module.AZURE['endpoint'])
            self.assertEqual(3072, self.module.EMBED_DIM)
        finally:
            os.unlink(ini_path)

    def test_embed_texts_uses_azure(self):
        self.module.AZURE.update({'key': 'abc', 'endpoint': 'https://example', 'deployment': 'model', 'api_version': '2024'})
        self.module.OPENAI['key'] = ''
        fake_vectors = [[0.1, 0.2], [0.3, 0.4]]

        with mock.patch.object(self.module, 'embed_azure', return_value=fake_vectors) as mock_embed:
            result = self.module.embed_texts(['a', 'b'], batch_size=2)

        mock_embed.assert_called_once_with(['a', 'b'])
        self.assertEqual(fake_vectors, result)

    def test_ensure_collection_validates_dimension(self):
        class DummyClient:
            def __init__(self):
                self.current_size = 256

            def create_collection(self, *args, **kwargs):
                raise Exception('exists')

            def get_collection(self, _name):
                cfg = SimpleNamespace(vectors=SimpleNamespace(size=self.current_size))
                return SimpleNamespace(config=cfg)

        client = DummyClient()
        self.module.ensure_collection(client, 'test', 256)

        client.current_size = 128
        with self.assertRaises(RuntimeError):
            self.module.ensure_collection(client, 'test', 256)

    def test_stream_text_chunks_batches(self):
        data = 'line1\nline2\nline3'
        with tempfile.NamedTemporaryFile('w', delete=False) as tmp:
            tmp.write(data)
            path = tmp.name

        try:
            with mock.patch.object(self.module, 'chunk_text', return_value=[data.replace('\n', ' ').upper()]):
                chunks = list(self.module.stream_text_chunks(path, max_tokens=10, overlap=2))
            self.assertEqual([data.replace('\n', ' ').upper()], chunks)
        finally:
            os.unlink(path)
