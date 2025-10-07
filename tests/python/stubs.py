import sys
import types

# Stub pymysql if unavailable
if 'pymysql' not in sys.modules:
    pymysql = types.ModuleType('pymysql')

    class DummyCursor:
        DictCursor = object()

    class CursorCtx:
        def __init__(self):
            self.queries = []

        def execute(self, *args, **kwargs):
            self.queries.append((args, kwargs))
            return 0

        def fetchone(self):
            return None

        def fetchall(self):
            return []

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

    class DummyConnection:
        def cursor(self):
            return CursorCtx()

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

        def close(self):
            pass

    def connect(**kwargs):
        return DummyConnection()

    pymysql.connect = connect
    cursors = types.SimpleNamespace(DictCursor=DummyCursor.DictCursor)
    pymysql.cursors = cursors
    sys.modules['pymysql'] = pymysql

# Stub qdrant_client if unavailable
if 'qdrant_client' not in sys.modules:
    qc_mod = types.ModuleType('qdrant_client')

    class DummyCollectionInfo:
        def __init__(self, size):
            vectors = types.SimpleNamespace(size=size)
            self.config = types.SimpleNamespace(vectors=vectors)

    class DummyQdrantClient:
        def __init__(self, *args, **kwargs):
            self.created = []
            self.upserts = []
            self.collection_size = kwargs.get('dim', 1536)

        def create_collection(self, collection_name, vectors_config):
            self.created.append((collection_name, vectors_config))
            # simulate already exists by raising only for duplicate test

        def get_collection(self, collection_name):
            size = getattr(self, 'collection_size', 1536)
            return DummyCollectionInfo(size)

        def upsert(self, collection_name, points, wait=True):
            self.upserts.append((collection_name, points, wait))

    qc_mod.QdrantClient = DummyQdrantClient

    http_mod = types.ModuleType('qdrant_client.http')
    models_mod = types.ModuleType('qdrant_client.http.models')

    class PointStruct:
        def __init__(self, id, vector, payload):
            self.id = id
            self.vector = vector
            self.payload = payload

    class Distance:
        COSINE = 'cosine'

    class VectorParams:
        def __init__(self, size, distance):
            self.size = size
            self.distance = distance

    class Filter:
        def __init__(self, *args, **kwargs):
            self.must = kwargs.get('must', [])

    class FieldCondition:
        def __init__(self, *args, **kwargs):
            self.key = kwargs.get('key')
            self.match = kwargs.get('match')

    class MatchValue:
        def __init__(self, value):
            self.value = value

    models_mod.PointStruct = PointStruct
    models_mod.Distance = Distance
    models_mod.VectorParams = VectorParams
    models_mod.Filter = Filter
    models_mod.FieldCondition = FieldCondition
    models_mod.MatchValue = MatchValue

    sys.modules['qdrant_client'] = qc_mod
    sys.modules['qdrant_client.http'] = http_mod
    sys.modules['qdrant_client.http.models'] = models_mod
    qc_mod.http = http_mod
    http_mod.models = models_mod

# Stub tiktoken if unavailable
if 'tiktoken' not in sys.modules:
    tiktoken = types.ModuleType('tiktoken')

    class DummyEncoding:
        def encode(self, text):
            return [ord(ch) for ch in text]

        def decode(self, tokens):
            return ''.join(chr(t) for t in tokens)

    def get_encoding(_name):
        return DummyEncoding()

    def encoding_for_model(_model):
        return DummyEncoding()

    tiktoken.get_encoding = get_encoding
    tiktoken.encoding_for_model = encoding_for_model
    sys.modules['tiktoken'] = tiktoken
