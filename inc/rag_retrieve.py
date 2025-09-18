#!/usr/bin/env python3
# rag_retrieve.py

import os, sys, json, time, re, configparser, requests, pymysql, warnings
from typing import Dict, Any, List
from qdrant_client import QdrantClient
from qdrant_client.http.models import Filter, FieldCondition, MatchValue
import tiktoken

warnings.filterwarnings("ignore", category=UserWarning)
warnings.filterwarnings("ignore", category=DeprecationWarning)

# ---- shared defaults (overridden by INI) ----
QDRANT_URL, QDRANT_API_KEY, QDRANT_COLLECTION = "http://127.0.0.1:6333", "", "nhlbi"
AZURE = {"key":"", "endpoint":"", "deployment":"NHLBI-Chat-workflow-text-embedding-3-large", "api_version":"2024-06-01"}
OPENAI = {"key":"", "base":"https://api.openai.com/v1", "model":"NHLBI-Chat-workflow-text-embedding-3-large"}
EMBED_DIM = 1536

_SENT_SPLIT = re.compile(r'(?<=[\.!\?])\s+|\n+')
_URL_RE     = re.compile(r'https?://\S+')
_PAGEMARK   = re.compile(r'^\s*-{2,}\s*Page\s+\d+\s*-{2,}\s*$', re.I)

def _clean_sentence(s: str) -> str:
    if _PAGEMARK.match(s.strip()):
        return ""
    s = _URL_RE.sub("", s)
    s = re.sub(r'\s+', ' ', s).strip()
    s = s.lstrip(",;:–—- ")
    return s

def _post_clean(snippet: str) -> str:
    snippet = re.sub(r'\s+', ' ', snippet).strip()
    snippet = snippet.lstrip(",;:–—- ")
    return snippet

def _hits_from_query(res):
    if hasattr(res, "points"):
        return res.points
    if isinstance(res, tuple) and len(res) >= 1:
        return res[0]
    return res

def _unquote(v:str)->str:
    v=v.strip()
    for token in (";","#"):
        if token in v: v=v.split(token,1)[0].rstrip()
    if len(v)>=2 and v[0] in ("'",'"') and v[-1]==v[0]:
        v=v[1:-1]
    return v

def load_ini(path:str):
    global QDRANT_URL,QDRANT_API_KEY,QDRANT_COLLECTION,AZURE,OPENAI,EMBED_DIM
    cfg = configparser.ConfigParser(interpolation=None)
    if not path or not cfg.read(path):
        raise RuntimeError(f"Config file not readable: {path}")
    if "qdrant" in cfg:
        QDRANT_URL        = _unquote(cfg["qdrant"].get("url", QDRANT_URL))
        QDRANT_API_KEY    = _unquote(cfg["qdrant"].get("api_key", QDRANT_API_KEY))
        QDRANT_COLLECTION = _unquote(cfg["qdrant"].get("collection", QDRANT_COLLECTION))
    if "azure-embedding" in cfg:
        AZURE["key"]        = _unquote(cfg["azure-embedding"].get("api_key", AZURE["key"]))
        AZURE["endpoint"]   = _unquote(cfg["azure-embedding"].get("url", AZURE["endpoint"]))
        AZURE["deployment"] = _unquote(cfg["azure-embedding"].get("deployment_name", AZURE["deployment"]))
        AZURE["api_version"]= _unquote(cfg["azure-embedding"].get("api_version", AZURE["api_version"]))
        EMBED_DIM = 3072 if "large" in AZURE["deployment"] else 1536
    if "openai-embedding" in cfg:
        OPENAI["key"]   = _unquote(cfg["openai-embedding"].get("api_key", OPENAI["key"]))
        OPENAI["base"]  = _unquote(cfg["openai-embedding"].get("base", OPENAI["base"]))
        OPENAI["model"] = _unquote(cfg["openai-embedding"].get("model", OPENAI["model"]))
        EMBED_DIM = 3072 if "large" in OPENAI["model"] else 1536

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

def embed_query(text:str)->List[float]:
    if AZURE["key"] and AZURE["endpoint"]:
        url = f"{AZURE['endpoint'].rstrip('/')}/openai/deployments/{AZURE['deployment']}/embeddings?api-version={AZURE['api_version']}"
        r = requests.post(url, headers={"api-key":AZURE["key"],"Content-Type":"application/json"}, json={"input": text},timeout=15)
        r.raise_for_status()
        return r.json()["data"][0]["embedding"]
    elif OPENAI["key"]:
        url = f"{OPENAI['base'].rstrip('/')}/embeddings"
        r = requests.post(url, headers={"Authorization":f"Bearer {OPENAI['key']}", "Content-Type":"application/json"},
                          json={"model":OPENAI["model"], "input":text}, timeout=15)
        r.raise_for_status()
        return r.json()["data"][0]["embedding"]
    else:
        raise RuntimeError("No embedding backend configured")

def _enc():
    try:
        return tiktoken.get_encoding("cl100k_base")
    except Exception:
        return tiktoken.encoding_for_model("gpt-4o-mini")

def _token_len(text: str) -> int:
    return len(_enc().encode(text))

def _sentences(txt: str):
    parts = _SENT_SPLIT.split(txt.strip())
    return [s.strip() for s in parts if s.strip()]

def _keywords(q: str):
    return {w for w in re.findall(r"[a-zA-Z]+", q.lower()) if len(w) >= 4}

def _score_sentence(sent: str, keys: set):
    if not keys:
        return 0
    s = set(re.findall(r"[a-zA-Z]+", sent.lower()))
    return len(s & keys)

def assemble_snippet(points, question: str, max_tokens: int):
    keys = _keywords(question)
    out = []
    used = set()
    budget = max_tokens

    hits = getattr(points, "points", points)

    for p in hits:
        pl = getattr(p, "payload", {}) or {}
        key = (pl.get("document_id"), pl.get("chunk_index"))
        if key in used:
            continue
        used.add(key)

        filename = pl.get("filename") or f"doc-{pl.get('document_id')}"
        page = pl.get("page_range")
        if page:
            cite_tag = f"【{filename}, p. {page}】"
        else:
            cite_tag = f"【{filename}】"

        sents = _sentences(pl.get("chunk_text",""))
        if not sents:
            continue

        scored = [( _score_sentence(s, keys), idx, s ) for idx, s in enumerate(sents)]
        scored.sort(reverse=True)

        chosen = []
        taken_idx = set()
        for sc, idx, s in scored:
            if len(chosen) >= 3 and sc == 0:
                break
            for j in (idx-1, idx, idx+1):
                if 0 <= j < len(sents) and j not in taken_idx:
                    taken_idx.add(j)
                    chosen.append(sents[j])
            if len(chosen) >= 5:
                break

        cleaned = [_clean_sentence(x) for x in chosen]
        cleaned = [x for x in cleaned if x]
        snippet = " ".join(cleaned).strip()
        if not snippet:
            continue

        candidate = f"{cite_tag} " + _post_clean(snippet) + "\n"

        need = _token_len(candidate)
        if need > budget:
            continue

        out.append(candidate)
        budget -= need
        if budget <= 0:
            break

    if not out:
        return "----- RAG CONTEXT BEGIN -----\n(No highly relevant passages found.)\n----- RAG CONTEXT END -----"

    return "----- RAG CONTEXT BEGIN -----\n" + "\n\n".join(out) + "\n----- RAG CONTEXT END -----"

def main():
    t0 = time.time()
    inp = read_input()
    question   = inp.get("question","")
    chat_id    = inp.get("chat_id")
    user       = inp.get("user")
    top_k      = int(inp.get("top_k", 12))
    max_ctx    = int(inp.get("max_context_tokens", 2000))
    ini_path   = inp.get("config_path")

    if not question or not chat_id or not user:
        print(json.dumps({"error":"missing question/chat_id/user"})); sys.exit(1)

    load_ini(ini_path)
    vec = embed_query(question)

    client = QdrantClient(url=QDRANT_URL, api_key=QDRANT_API_KEY, timeout=15.0)
    flt = Filter(
        must=[
            FieldCondition(key="user_id",  match=MatchValue(value=user)),
            FieldCondition(key="chat_id",  match=MatchValue(value=chat_id)),
            FieldCondition(key="deleted",  match=MatchValue(value=False)),
        ]
    )

    res = client.query_points(
        collection_name=QDRANT_COLLECTION,
        query=vec,
        query_filter=flt,
        limit=top_k,
        with_payload=True,
        with_vectors=False,
    )

    hits = res.points if hasattr(res, "points") else res
    context = assemble_snippet(hits, question, max_tokens=max_ctx)

    citations = []
    for p in hits:
        pl = getattr(p, "payload", {}) or {}
        citations.append({
            "document_id": pl.get("document_id"),
            "filename": pl.get("filename"),
            "page": pl.get("page_range"),
            "section": pl.get("section"),
            "score": getattr(p, "score", None),
        })

    augmented_prompt = (
        f"{context}\n\n"
        "Use the context above when helpful. If something isn't covered, answer normally. "
        "Cite sources inline using the brackets provided (e.g., 【filename, p. X】)."
        "\n\nUser question:\n" + question
    )

    out = {
        "ok": True,
        "augmented_prompt": augmented_prompt,
        "citations": citations,
        "retrieved": len(hits),
        "latency_ms": int((time.time()-t0)*1000),
        "embedding_model_used": AZURE['deployment'] if AZURE["key"] else OPENAI["model"],
        "collection": QDRANT_COLLECTION
    }
    print(json.dumps(out))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)})); sys.exit(1)

