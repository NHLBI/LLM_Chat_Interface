import configparser
import os
import unittest
import uuid

try:
    from qdrant_client import QdrantClient, models
except ImportError:  # pragma: no cover - optional dependency
    QdrantClient = None


class QdrantIntegrationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if QdrantClient is None:
            raise unittest.SkipTest("qdrant_client package not available")

        config_path = os.environ.get("CHAT_CONFIG_PATH", "/etc/apps/chatdev_config.ini")
        parser = configparser.ConfigParser()
        if not parser.read(config_path):
            raise unittest.SkipTest("chat configuration not available")
        if "qdrant" not in parser:
            raise unittest.SkipTest("qdrant configuration missing")

        cls._collection_prefix = f"codex_test_{uuid.uuid4().hex}"
        cls._url = parser["qdrant"].get("url")
        cls._api_key = parser["qdrant"].get("api_key", fallback=None) or None

        if not cls._url:
            raise unittest.SkipTest("qdrant URL not configured")

        try:
            cls._client = QdrantClient(url=cls._url, api_key=cls._api_key, timeout=2.0)
            cls._client.get_collections()
        except Exception as exc:  # pragma: no cover
            raise unittest.SkipTest(f"qdrant unavailable: {exc}")

    def test_qdrant_vector_round_trip(self):
        collection = f"{self._collection_prefix}"

        try:
            self._client.recreate_collection(
                collection_name=collection,
                vectors_config=models.VectorParams(size=4, distance=models.Distance.COSINE),
            )
            self._client.upsert(
                collection_name=collection,
                wait=True,
                points=[
                    models.PointStruct(
                        id=1,
                        vector=[0.1, 0.0, 0.0, 0.0],
                        payload={"text": "hello world"},
                    )
                ],
            )

            results = self._client.search(
                collection_name=collection,
                query_vector=[0.1, 0.0, 0.0, 0.0],
                limit=1,
            )
            self.assertGreater(len(results), 0)
            self.assertEqual(results[0].payload.get("text"), "hello world")
        finally:
            try:
                self._client.delete_collection(collection_name=collection)
            except Exception:
                pass


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
