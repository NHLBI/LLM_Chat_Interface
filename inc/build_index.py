#!/usr/bin/env python3
# build_index.py

import os, sys, json, hashlib, time, math, random, signal
from typing import List, Dict, Any
import pymysql
import requests
import tiktoken
import configparser

from qdrant_client import QdrantClient
from qdrant_client.http.models import PointStruct, Distance, VectorParams

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

# ---------------- Config helpers ----------------
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
    for token in (";", "#"):
        if token in v:
            v = v.split(token, 1)[0].rstrip()
    if len(v) >= 2 and v[0] in ("'", '"') and v[-1] == v[0]:
        v = v[1:-1]
    return v

def load_ini(path: str):
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

    # embeddings (azure)
    if "azure-embedding" in cfg:
        AZURE["key"]        = _unquote(cfg["azure-embedding"].get("api_key", AZURE["key"]))
        AZURE["endpoint"]   = _unquote(cfg["azure-embedding"].get("url", AZURE["endpoint"]))
        AZURE["deployment"] = _unquote(cfg["azure-embedding"].get("deployment_name", AZURE["deployment"]))
        AZURE["api_version"]= _unquote(cfg["azure-embedding"].get("api_version", AZURE["api_version"]))
        EMBED_DIM = 3072 if "large" in AZURE["deployment"] else 1536

    # or OpenAI-compatible backend
    if "openai-embedding" in cfg:
        OPENAI["key"]   = _unquote(cfg["openai-embedding"].get("api_key", OPENAI["key"]))
        OPENAI["base"]  = _unquote(cfg["openai-embedding"].get("base", OPENAI["base"]))
        OPENAI["model"] = _unquote(cfg["openai-embedding"].get("model", OPENAI["model"]))
        EMBED_DIM = 3072 if "large" in OPENAI["model"] else 1536

# ---------------- Utilities ----------------
def read_input() -> Dict[str, Any]:
    if len(sys.argv) >= 3 and sys.argv[1] == "--json":
        with open(sys.argv[2], "r") as f:
            return json.load(f)
    if not sys.stdin.isatty():
        data = sys.stdin.read()
        if data.strip():
            return json.loads(data)
        raise RuntimeError("No JSON provided on stdin and no --json file specified")
    raise RuntimeError("usage: build_index.py --json file.json  (or pipe JSON to stdin)")

def db_conn():
    return pymysql.connect(**DB)

def token_encoder():
    try:
        return tiktoken.get_encoding("cl100k_base")
    except Exception:
        return tiktoken.encoding_for_model("gpt-4o-mini")  # fallback

def chunk_text(text: str, max_tokens=450, overlap=50) -> List[str]:
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

def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for b in iter(lambda: f.read(1024 * 1024), b""):
            h.update(b)
    return h.hexdigest()

# ---------------- Robust HTTP with retry ----------------
def _post_json_with_retry(url: str, headers: Dict[str, str], payload: Dict[str, Any], timeout: int = 60):
    backoff = 1.0
    for attempt in range(8):
        try:
            r = requests.post(url, headers=headers, json=payload, timeout=timeout)
            if r.status_code in (429, 500, 502, 503, 504):
                raise requests.HTTPError(f"{r.status_code} {r.text[:200]}")
            r.raise_for_status()
            return r
        except Exception as e:
            if attempt >= 7:
                raise
            time.sleep(backoff + random.random() * 0.5)
            backoff = min(backoff * 2.0, 30.0)

# ---------------- Embeddings ----------------
def embed_azure(batch: List[str]) -> List[List[float]]:
    url = f"{AZURE['endpoint'].rstrip('/')}/openai/deployments/{AZURE['deployment']}/embeddings?api-version={AZURE['api_version']}"
    headers = {"api-key": AZURE["key"], "Content-Type": "application/json"}
    r = _post_json_with_retry(url, headers, {"input": batch}, timeout=90)
    data = r.json()
    vectors = [None] * len(batch)
    for item in data["data"]:
        vectors[item["index"]] = item["embedding"]
    return vectors

def embed_openai(batch: List[str]) -> List[List[float]]:
    headers = {"Authorization": f"Bearer {OPENAI['key']}", "Content-Type": "application/json"}
    url = f"{OPENAI['base'].rstrip('/')}/embeddings"
    r = _post_json_with_retry(url, headers, {"model": OPENAI["model"], "input": batch}, timeout=90)
    data = r.json()
    vectors = [None] * len(batch)
    for item in data["data"]:
        vectors[item["index"]] = item["embedding"]
    return vectors

def embed_texts(texts: List[str], batch_size=64) -> List[List[float]]:
    if AZURE["key"] and AZURE["endpoint"]:
        fn = embed_azure
    elif OPENAI["key"]:
        fn = embed_openai
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

# ---------------- Qdrant ----------------
def qdrant_client() -> QdrantClient:
    # bump timeout so large upserts don't choke
    return QdrantClient(url=QDRANT_URL, api_key=QDRANT_API_KEY, timeout=600.0)

def ensure_collection(client: QdrantClient, collection: str, dim: int):
    try:
        client.create_collection(
            collection_name=collection,
            vectors_config=VectorParams(size=dim, distance=Distance.COSINE),
        )
        return
    except Exception:
        info = client.get_collection(collection)
        vc = getattr(info, "config", None)
        vc = getattr(vc, "vectors", None) or getattr(vc, "vectors_config", None)
        size = getattr(vc, "size", None)
        if isinstance(vc, dict):
            size = vc.get("size")
        if size and int(size) != int(dim):
            raise RuntimeError(
                f"Collection '{collection}' exists with vector size {size}, need {dim}. Drop & recreate."
            )

def _point_id_from_payload(p: Dict[str, Any]) -> str:
    import uuid
    key = f"{p['document_id']}|{p['version']}|{p['embedding_model']}|{p['chunk_index']}"
    return str(uuid.uuid5(uuid.NAMESPACE_URL, key))

def upsert_points(client: QdrantClient, collection: str,
                  vectors: List[List[float]],
                  payloads: List[Dict[str, Any]]):
    pts = [PointStruct(id=_point_id_from_payload(p), vector=v, payload=p) for v, p in zip(vectors, payloads)]
    client.upsert(collection_name=collection, points=pts, wait=True)

# ---------------- Text streaming ----------------
def _open_text(path: str):
    return open(path, "r", encoding="utf-8", errors="ignore")

def stream_text_chunks(file_path: str, max_tokens: int, overlap: int):
    buf = []
    chars = 0
    target_chars = 200_000
    with _open_text(file_path) as f:
        for line in f:
            buf.append(line.rstrip("\n"))
            chars += len(buf[-1]) + 1
            if chars >= target_chars:
                text = " ".join(buf).strip()
                if text:
                    for ch in chunk_text(text, max_tokens=max_tokens, overlap=overlap):
                        yield ch
                buf, chars = [], 0
        if buf:
            text = " ".join(buf).strip()
            if text:
                for ch in chunk_text(text, max_tokens=max_tokens, overlap=overlap):
                    yield ch

# ---------------- Graceful shutdown ----------------
_SHOULD_STOP = False
def _sigterm(_signum, _frame):
    global _SHOULD_STOP
    _SHOULD_STOP = True
signal.signal(signal.SIGTERM, _sigterm)
signal.signal(signal.SIGINT, _sigterm)

# ---------------- Main ----------------
def DBG(msg: str):
    sys.stderr.write(f"[build_index] {msg}\n")
    sys.stderr.flush()

def main():
    t0 = time.time()
    inp = read_input()
    DBG(f"input received; keys={list(inp.keys())}")


    ini_path = inp.get("config_path") or _guess_config_path_from_dir()
    try:
        load_ini(ini_path)
    except Exception as e:
        DBG(f"ERROR loading config: {e}")
        print(json.dumps({"ok": False, "error": f"config load failed: {str(e)}", "config_path": ini_path}))
        return

    document_id = int(inp["document_id"])
    chat_id = inp.get("chat_id")
    user = inp.get("user")
    embedding_model = inp.get("embedding_model") or (AZURE["deployment"] if AZURE["key"] and AZURE["endpoint"] else OPENAI["model"])

    file_path = inp.get("file_path")
    filename_override = inp.get("filename") or None
    chunk_tok = int(inp.get("chunk_tokens", 450))
    chunk_ovl = int(inp.get("chunk_overlap", 50))
    cleanup_tmp = bool(inp.get("cleanup_tmp", False))

    DBG(f"start doc_id={document_id} chat_id={chat_id} user={user} file={file_path}")
    DBG(f"config: collection={QDRANT_COLLECTION} model={embedding_model} dim={EMBED_DIM}")

    if not file_path or not os.path.exists(file_path):
        DBG(f"file not found: {file_path}")
        print(json.dumps({"ok": False, "error": f"file_path not found: {file_path}"}))
        return

    with db_conn() as conn, conn.cursor() as cur:
        cur.execute("SELECT id, chat_id, name, type, content, file_sha256, content_sha256, version, deleted FROM document WHERE id=%s", (document_id,))
        doc = cur.fetchone()
        if not doc:
            DBG("document not found in DB")
            print(json.dumps({"ok": False, "error": f"document_id {document_id} not found"})); return
        if doc["deleted"]:
            DBG("document marked deleted")
            print(json.dumps({"ok": False, "error": "document is marked deleted"})); return

        filename = filename_override or doc["name"]

        new_content_sha = sha256_file(file_path)
        if doc["content_sha256"] != new_content_sha:
            cur.execute("UPDATE document SET content_sha256=%s WHERE id=%s", (new_content_sha, document_id))
        DBG(f"content_sha256={new_content_sha[:12]}...")

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
            DBG(f"rag_index created id={rag_index_id}")
        else:
            rag_index_id = ri["id"]
            cur.execute("UPDATE rag_index SET ready=0 WHERE id=%s", (rag_index_id,))
            DBG(f"rag_index reuse id={rag_index_id} (reset ready=0)")

    qc = qdrant_client()
    ensure_collection(qc, QDRANT_COLLECTION, EMBED_DIM)
    DBG("qdrant collection ensured")

    total_chunks = 0
    batch_texts: List[str] = []
    batch_payloads: List[Dict[str, Any]] = []
    chunk_idx = 0
    virtual_page = 0
    FLUSH_EVERY = 256

    def flush_batch():
        nonlocal total_chunks, batch_texts, batch_payloads
        if not batch_texts:
            return
        DBG(f"embedding batch size={len(batch_texts)}")
        vecs = embed_texts(batch_texts, batch_size=64)
        DBG("upserting to qdrant")
        upsert_points(qc, QDRANT_COLLECTION, vecs, batch_payloads)
        total_chunks += len(batch_texts)
        DBG(f"progress total_chunks={total_chunks}")
        with db_conn() as conn, conn.cursor() as cur:
            cur.execute("UPDATE rag_index SET chunk_count=%s WHERE id=%s", (total_chunks, rag_index_id))
        batch_texts, batch_payloads = [], []

    try:
        for ch in stream_text_chunks(file_path, max_tokens=chunk_tok, overlap=chunk_ovl):
            if _SHOULD_STOP:
                raise RuntimeError("Indexing interrupted")
            virtual_page += 1
            batch_texts.append(ch)
            batch_payloads.append({
                "user_id": user,
                "chat_id": chat_id,
                "document_id": document_id,
                "version": doc.get("version", 1),
                "filename": filename,
                "page_range": str(virtual_page),
                "section": None,
                "deleted": False,
                "content_sha256": new_content_sha,
                "embedding_model": embedding_model,
                "chunk_index": chunk_idx,
                "chunk_text": ch
            })
            chunk_idx += 1
            if len(batch_texts) >= FLUSH_EVERY:
                flush_batch()

        flush_batch()
        with db_conn() as conn, conn.cursor() as cur:
            cur.execute("UPDATE rag_index SET chunk_count=%s, ready=1, content_sha256=%s WHERE id=%s",
                        (total_chunks, new_content_sha, rag_index_id))
        DBG(f"done chunks={total_chunks}, elapsed={round(time.time()-t0,2)}s")

        out = {
            "ok": True,
            "document_id": document_id,
            "content_sha256": new_content_sha,
            "chunk_count": total_chunks,
            "elapsed_sec": round(time.time() - t0, 3),
            "streamed_file": True
        }
        print(json.dumps(out))

    except Exception as e:
        import traceback
        DBG("ERROR: " + str(e))
        DBG(traceback.format_exc())
        with db_conn() as conn, conn.cursor() as cur:
            cur.execute("UPDATE rag_index SET ready=0 WHERE id=%s", (rag_index_id,))
        print(json.dumps({"ok": False, "error": str(e)}))
        raise
    finally:
        if cleanup_tmp:
            try:
                os.remove(file_path)
                DBG(f"cleanup tmp removed {file_path}")
            except Exception as _:
                DBG(f"cleanup tmp failed {file_path}")

