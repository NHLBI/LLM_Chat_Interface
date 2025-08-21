#!/usr/bin/env python3
# build_index.py
import os, sys, json, hashlib, time, uuid
from typing import List, Dict, Any
import pymysql
import requests
import tiktoken
import configparser

from qdrant_client import QdrantClient
from qdrant_client.http.models import PointStruct, Distance, VectorParams

from uuid import uuid5, NAMESPACE_URL


# ---------------- Defaults (override via INI) ----------------
DB = {
    "host": "127.0.0.1",
    "port": 3306,
    "user": None,
    "password": None,
    "db": "osi_chat_dev",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
    "autocommit": True,
}

QDRANT_URL        = "http://127.0.0.1:6333"
QDRANT_API_KEY    = ""
QDRANT_COLLECTION = "nhlbi"

AZURE = {
    "key": "",
    "endpoint": "",
    "deployment": "text-embedding-3-small",
    "api_version": "2024-06-01",
}
OPENAI = {
    "key": "",
    "base": "https://api.openai.com/v1",
    "model": "text-embedding-3-small",
}

# 1536 for text-embedding-3-small, 3072 for -large
EMBED_DIM = 1536

# put near the top of build_index.py, after imports
def _guess_config_path_from_dir() -> str:
    base = "/etc/apps"
    d = os.path.dirname(__file__)
    env = ""
    if "chatdev" in d:
        env = "dev"
    elif "chattest" in d:
        env = "test"
    return f"{base}/chat{env}_config.ini"

def _unquote(v: str) -> str:
    v = v.strip()
    # strip inline comments
    for token in (";", "#"):
        if token in v:
            v = v.split(token, 1)[0].rstrip()
    # remove one layer of wrapping quotes
    if len(v) >= 2 and v[0] in ("'", '"') and v[-1] == v[0]:
        v = v[1:-1]
    return v

def load_ini(path: str):
    """Load settings from your chatdev_config.ini and override globals."""
    global DB, QDRANT_URL, QDRANT_API_KEY, QDRANT_COLLECTION, AZURE, OPENAI, EMBED_DIM
    cfg = configparser.ConfigParser(interpolation=None)
    if not path or not cfg.read(path):
        raise RuntimeError(f"Config file not readable: {path}")

    # database
    if "database" in cfg:
        DB["host"]     = _unquote(cfg["database"].get("host", DB["host"]))
        DB["db"]       = _unquote(cfg["database"].get("dbname", DB["db"]))
        DB["user"]     = _unquote(cfg["database"].get("username", DB["user"]))
        DB["password"] = _unquote(cfg["database"].get("password", DB["password"]))

    # qdrant
    if "qdrant" in cfg:
        QDRANT_URL        = _unquote(cfg["qdrant"].get("url", QDRANT_URL))
        QDRANT_API_KEY    = _unquote(cfg["qdrant"].get("api_key", QDRANT_API_KEY))
        QDRANT_COLLECTION = _unquote(cfg["qdrant"].get("collection", QDRANT_COLLECTION))

    # embeddings (azure-embedding section)
    if "azure-embedding" in cfg:
        AZURE["key"]        = _unquote(cfg["azure-embedding"].get("api_key", AZURE["key"]))
        AZURE["endpoint"]   = _unquote(cfg["azure-embedding"].get("url", AZURE["endpoint"]))
        AZURE["deployment"] = _unquote(cfg["azure-embedding"].get("deployment_name", AZURE["deployment"]))
        AZURE["api_version"]= _unquote(cfg["azure-embedding"].get("api_version", AZURE["api_version"]))
        # infer dim from deployment name if you ever switch to -large
        EMBED_DIM = 3072 if "large" in AZURE["deployment"] else 1536

    # or OpenAI-compatible backend
    if "openai-embedding" in cfg:
        OPENAI["key"]   = _unquote(cfg["openai-embedding"].get("api_key", OPENAI["key"]))
        OPENAI["base"]  = _unquote(cfg["openai-embedding"].get("base", OPENAI["base"]))
        OPENAI["model"] = _unquote(cfg["openai-embedding"].get("model", OPENAI["model"]))
        EMBED_DIM = 3072 if "large" in OPENAI["model"] else 1536


# --------------- Utilities ---------------

def read_input() -> Dict[str, Any]:
    if len(sys.argv) >= 3 and sys.argv[1] == "--json":
        with open(sys.argv[2], "r") as f:
            return json.load(f)
    if not sys.stdin.isatty():
        data = sys.stdin.read()
        if data.strip():
            return json.loads(data)
        raise RuntimeError("No JSON provided on stdin and no --json file specified")
    raise RuntimeError("usage: rag_retrieve.py --json file.json  (or pipe JSON to stdin)")

def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()

def normalize_text(text: str) -> str:
    # basic normalization; keep page/section markers if you added them in parser
    return " ".join(text.replace("\r", "\n").split())

def db_conn():
    return pymysql.connect(**DB)

def token_encoder():
    # cl100k_base is a good default for OpenAI style tokenization
    try:
        return tiktoken.get_encoding("cl100k_base")
    except Exception:
        return tiktoken.encoding_for_model("gpt-4o-mini")  # fallback

def chunk_text(text: str, max_tokens=520, overlap=64) -> List[str]:
    enc = token_encoder()
    toks = enc.encode(text)
    out = []
    start = 0
    while start < len(toks):
        end = min(start + max_tokens, len(toks))
        chunk = enc.decode(toks[start:end])
        out.append(chunk)
        if end == len(toks):
            break
        start = max(0, end - overlap)
    return out

# --------------- Embeddings ---------------

def embed_azure(batch: List[str]) -> List[List[float]]:
    url = f"{AZURE['endpoint'].rstrip('/')}/openai/deployments/{AZURE['deployment']}/embeddings?api-version={AZURE['api_version']}"
    headers = {"api-key": AZURE["key"], "Content-Type": "application/json"}
    resp = requests.post(url, headers=headers, json={"input": batch})
    resp.raise_for_status()
    data = resp.json()
    # sort by index to preserve order
    vectors = [None] * len(batch)
    for item in data["data"]:
        vectors[item["index"]] = item["embedding"]
    return vectors

def embed_openai(batch: List[str]) -> List[List[float]]:
    headers = {"Authorization": f"Bearer {OPENAI['key']}", "Content-Type": "application/json"}
    url = f"{OPENAI['base'].rstrip('/')}/embeddings"
    resp = requests.post(url, headers=headers, json={"model": OPENAI["model"], "input": batch})
    resp.raise_for_status()
    data = resp.json()
    vectors = [None] * len(batch)
    for item in data["data"]:
        vectors[item["index"]] = item["embedding"]
    return vectors

def embed_texts(texts: List[str], batch_size=64) -> List[List[float]]:
    if AZURE["key"] and AZURE["endpoint"]:
        fn = embed_azure
        model_name = AZURE["deployment"]
    elif OPENAI["key"]:
        fn = embed_openai
        model_name = OPENAI["model"]
    else:
        raise RuntimeError("No embedding backend configured (set Azure or OpenAI env).")

    vectors: List[List[float]] = []
    for i in range(0, len(texts), batch_size):
        batch = texts[i:i+batch_size]
        vecs = fn(batch)
        if any(v is None for v in vecs):
            raise RuntimeError("Embedding response missing vectors")
        vectors.extend(vecs)
    return vectors

# --------------- Qdrant ---------------

def qdrant_client() -> QdrantClient:
    return QdrantClient(url=QDRANT_URL, api_key=QDRANT_API_KEY, timeout=60.0)

def _point_id_from_payload(p: Dict[str, Any]) -> str:
    # deterministic, valid UUID
    key = f"{p['document_id']}|{p['version']}|{p['embedding_model']}|{p['chunk_index']}"
    return str(uuid5(NAMESPACE_URL, key))

def upsert_points(client: QdrantClient, collection: str,
                  vectors: List[List[float]],
                  payloads: List[Dict[str, Any]]):
    assert len(vectors) == len(payloads)
    points = []
    for v, p in zip(vectors, payloads):
        points.append(
            PointStruct(id=_point_id_from_payload(p), vector=v, payload=p)
        )
    client.upsert(collection_name=collection, points=points, wait=True)

def ensure_collection(client: QdrantClient, collection: str, dim: int):
    """
    Create collection if missing. If it already exists with a different
    vector size, raise a clear error so you can recreate it.
    """
    try:
        client.create_collection(
            collection_name=collection,
            vectors_config=VectorParams(size=dim, distance=Distance.COSINE),
        )
        return
    except Exception as e:
        # likely "already exists" – verify size
        try:
            info = client.get_collection(collection)
        except Exception:
            raise

        # Best-effort extraction of size across client versions
        vc = None
        cfg = getattr(info, "config", None)
        if cfg is not None:
            vc = getattr(cfg, "vectors", None) or getattr(cfg, "vectors_config", None)

        size = getattr(vc, "size", None)
        if isinstance(vc, dict):
            size = vc.get("size")

        if size and int(size) != int(dim):
            raise RuntimeError(
                f"Collection '{collection}' exists with vector size {size}, "
                f"but we need {dim}. Drop & recreate the collection."
            )
        # else OK

# --------------- Main indexing flow ---------------

def main():
    t0 = time.time()
    inp = read_input()

    # Load your PHP-resolved INI path if provided
    ini_path = inp.get("config_path") or _guess_config_path_from_dir()
    load_ini(ini_path)

    document_id = int(inp["document_id"])
    chat_id = inp.get("chat_id")
    user = inp.get("user")

    # Use whatever embedding backend we actually configured in the INI
    embedding_model = (
        inp.get("embedding_model")
        or (AZURE["deployment"] if AZURE["key"] and AZURE["endpoint"] else OPENAI["model"])
    )
    sys.stderr.write(
        f"[build_index] collection={QDRANT_COLLECTION} model={embedding_model} dim={EMBED_DIM}\n"
    )


    # Fetch document
    with db_conn() as conn, conn.cursor() as cur:
        cur.execute("SELECT id, chat_id, name, type, content, file_sha256, content_sha256, version, deleted FROM document WHERE id=%s", (document_id,))
        doc = cur.fetchone()
        if not doc:
            print(json.dumps({"ok": False, "error": f"document_id {document_id} not found"}))
            return
        if doc["deleted"]:
            print(json.dumps({"ok": False, "error": "document is marked deleted"}))
            return

        filename = doc["name"]
        # normalize & hash
        raw_text = doc["content"] or ""
        norm = normalize_text(raw_text)
        new_content_sha = sha256_text(norm)

        # If content_sha256 blank or changed, update document row
        if doc["content_sha256"] != new_content_sha:
            cur.execute("UPDATE document SET content_sha256=%s WHERE id=%s", (new_content_sha, document_id))

        # Insert/ensure rag_index row
        # If an entry exists for (document_id, embedding_model, version) reuse it; else create
        cur.execute("""SELECT id, ready, chunk_count FROM rag_index 
                       WHERE document_id=%s AND embedding_model=%s AND version=%s""",
                    (document_id, embedding_model, doc["version"]))
        ri = cur.fetchone()
        if not ri:
            cur.execute("""INSERT INTO rag_index
                           (document_id, chat_id, user, file_sha256, content_sha256, version, embedding_model, vector_backend, collection, chunk_count, ready)
                           VALUES (%s,%s,%s,%s,%s,%s,%s,'qdrant',%s,0,0)""",
                        (document_id, doc["chat_id"], user or "", doc["file_sha256"], new_content_sha, doc["version"], embedding_model, QDRANT_COLLECTION))
            rag_index_id = cur.lastrowid
        else:
            rag_index_id = ri["id"]
            # reset ready if we’re reindexing
            cur.execute("UPDATE rag_index SET ready=0 WHERE id=%s", (rag_index_id,))

    # Chunk
    # Chunk (DEBUG: tiny to see retrieval behavior clearly)
    chunks = chunk_text(norm, max_tokens=520, overlap=64)

    if not chunks:
        print(json.dumps({"ok": False, "error": "no text after normalization"}))
        return

    # Build payloads
    payloads = []
    for idx, chunk in enumerate(chunks):
        payloads.append({
            "user_id": user,
            "chat_id": chat_id or doc.get("chat_id"),
            "document_id": document_id,
            "version": doc.get("version", 1),
            "filename": filename,
            "page_range": None,      # fill if you have per-chunk pages in parser
            "section": None,         # fill if you keep section headings
            "deleted": False,
            "content_sha256": new_content_sha,
            "embedding_model": embedding_model,
            "chunk_index": idx,
            "chunk_text": chunk
        })

    # Embed
    vectors = embed_texts(chunks, batch_size=64)
    if any(len(v) != EMBED_DIM for v in vectors):
        # not fatal; Qdrant accepts any fixed-length you created the collection for
        pass

    # Upsert
    qc = qdrant_client()
    ensure_collection(qc, QDRANT_COLLECTION, EMBED_DIM)   # <--- ADD THIS LINE

    # Upsert in batches to keep payload sizes sane
    batch = 256
    for i in range(0, len(vectors), batch):
        upsert_points(qc, QDRANT_COLLECTION, vectors[i:i+batch], payloads[i:i+batch])

    # Mark ready & chunk_count
    with db_conn() as conn, conn.cursor() as cur:
        cur.execute("UPDATE rag_index SET chunk_count=%s, ready=1, content_sha256=%s WHERE document_id=%s AND embedding_model=%s AND version=%s",
                    (len(chunks), new_content_sha, document_id, embedding_model, doc.get("version", 1)))

    out = {
        "ok": True,
        "document_id": document_id,
        "content_sha256": new_content_sha,
        "chunk_count": len(chunks),
        "skipped_dedupe": False,
        "elapsed_sec": round(time.time() - t0, 3),
    }
    print(json.dumps(out))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
        sys.exit(1)

