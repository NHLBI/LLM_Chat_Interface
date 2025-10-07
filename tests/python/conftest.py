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

# Stub heavy parser dependencies when unavailable to keep parser_multi importable in CI.
if 'pandas' not in sys.modules:
    pandas_mod = types.ModuleType('pandas')

    class _DummyFrame:
        def to_json(self, *args, **kwargs):
            return '[]'

    def _dummy_frame(*args, **kwargs):
        return _DummyFrame()

    pandas_mod.DataFrame = _DummyFrame
    pandas_mod.read_csv = lambda *args, **kwargs: _DummyFrame()
    pandas_mod.read_excel = lambda *args, **kwargs: {'Sheet1': _DummyFrame()}
    sys.modules['pandas'] = pandas_mod

if 'PIL' not in sys.modules:
    pil_mod = types.ModuleType('PIL')
    pil_image_mod = types.ModuleType('PIL.Image')

    class _DummyImage:
        pass

    def _dummy_open(*args, **kwargs):
        return _DummyImage()

    pil_image_mod.open = _dummy_open
    pil_mod.Image = pil_image_mod
    sys.modules['PIL'] = pil_mod
    sys.modules['PIL.Image'] = pil_image_mod

if 'pytesseract' not in sys.modules:
    pytesseract_mod = types.ModuleType('pytesseract')
    pytesseract_mod.image_to_string = lambda *args, **kwargs: ''
    sys.modules['pytesseract'] = pytesseract_mod

if 'fitz' not in sys.modules:
    fitz_mod = types.ModuleType('fitz')

    class _DummyDoc:
        def __iter__(self):
            return iter([])

        def extract_image(self, *_args, **_kwargs):
            return {'image': b''}

    fitz_mod.open = lambda *_args, **_kwargs: _DummyDoc()
    sys.modules['fitz'] = fitz_mod

if 'docx' not in sys.modules:
    docx_mod = types.ModuleType('docx')

    class _DummyDocument:
        def __init__(self, *_args, **_kwargs):
            pass

        def iter_inner_content(self):
            return []

        @property
        def part(self):
            class _Part:
                _rels = {}

            return _Part()

    docx_mod.Document = _DummyDocument
    docx_mod.__path__ = []
    sys.modules['docx'] = docx_mod

    docx_text_mod = types.ModuleType('docx.text')
    docx_text_mod.__path__ = []
    sys.modules['docx.text'] = docx_text_mod

    paragraph_mod = types.ModuleType('docx.text.paragraph')

    class Paragraph:
        text = ''

    paragraph_mod.Paragraph = Paragraph
    sys.modules['docx.text.paragraph'] = paragraph_mod
    docx_mod.text = types.SimpleNamespace(paragraph=paragraph_mod)

    table_mod = types.ModuleType('docx.table')
    table_mod.__path__ = []

    class Table:
        rows = []

    table_mod.Table = Table
    sys.modules['docx.table'] = table_mod
    docx_mod.table = table_mod

if 'pptx' not in sys.modules:
    pptx_mod = types.ModuleType('pptx')

    class _DummyPresentation:
        def __init__(self, *_args, **_kwargs):
            self.slides = []

    pptx_mod.Presentation = _DummyPresentation
    pptx_mod.__path__ = []
    sys.modules['pptx'] = pptx_mod

    pptx_enum_mod = types.ModuleType('pptx.enum')
    pptx_enum_mod.__path__ = []
    sys.modules['pptx.enum'] = pptx_enum_mod

    shapes_mod = types.ModuleType('pptx.enum.shapes')
    shapes_mod.__path__ = []

    class _ShapeType:
        GROUP = 1
        PICTURE = 2

    shapes_mod.MSO_SHAPE_TYPE = _ShapeType
    sys.modules['pptx.enum.shapes'] = shapes_mod
    pptx_mod.enum = types.SimpleNamespace(shapes=shapes_mod)

if 'xlrd' not in sys.modules:
    xlrd_mod = types.ModuleType('xlrd')

    class _DummySheet:
        name = 'Sheet1'

        def get_rows(self):
            return []

    class _DummyBook:
        def sheets(self):
            return [_DummySheet()]

    xlrd_mod.open_workbook = lambda *_args, **_kwargs: _DummyBook()
    sys.modules['xlrd'] = xlrd_mod
