#!/usr/bin/env python3
import os
import json
import faiss
import numpy as np
import openai
import configparser

# === CONFIG ===
CONFIG_PATH = os.environ.get("CHAT_CONFIG_PATH", "/etc/apps/chatdev_config.ini")
def load_azure_credentials():
    parser = configparser.ConfigParser()
    if not parser.read(CONFIG_PATH):
        raise RuntimeError(f"Unable to read chat configuration at {CONFIG_PATH}")
    default_deployment = parser.get("azure", "default", fallback=None)
    if not default_deployment or default_deployment not in parser:
        raise RuntimeError("Azure default deployment not defined in configuration.")
    endpoint = parser[default_deployment].get("url", "").rstrip("/")
    api_key = parser[default_deployment].get("api_key", "")
    if not endpoint or not api_key:
        raise RuntimeError("Azure endpoint or API key missing from configuration.")
    return endpoint, api_key

AZURE_OPENAI_ENDPOINT, AZURE_OPENAI_API_KEY = load_azure_credentials()
EMBEDDING_MODEL = "NHLBI-Chat-workflow-text-embedding-3-large"
CHAT_MODEL = "NHLBI-Chat-gpt-4o"
API_VERSION = "2024-12-01-preview"

# === INIT OPENAI FOR AZURE ===

openai.api_type = "azure"
openai.api_version = API_VERSION
openai.api_key = AZURE_OPENAI_API_KEY
openai.azure_endpoint = AZURE_OPENAI_ENDPOINT


# === SAMPLE DOCUMENT ===
DOCUMENTS = [
    {"section": "Sole Source", "text": "As of March, 2025, Sole-source contracts may be used when only one source is available due to urgency or unique capability - in the originating state or district."},
    {"section": "Competition", "text": "Federal agencies are generally required to use full and open competition when awarding contracts."},
    {"section": "Small Business", "text": "Contracts may be set aside for small businesses if certain criteria are met under FAR Subpart 19."}
]

# === CREATE EMBEDDINGS AND BUILD FAISS INDEX ===
embeddings = []
metadata = []

for doc in DOCUMENTS:
    response = openai.embeddings.create(
        input=[doc["text"]],
        model=EMBEDDING_MODEL
    )
    vector = response.data[0].embedding
    embeddings.append(vector)
    metadata.append(doc)

dimension = len(embeddings[0])
index = faiss.IndexFlatL2(dimension)
index.add(np.array(embeddings).astype("float32"))

# === USER PROMPT ===
user_question = "When is it allowed to use sole-source contracts?"

# === EMBED THE QUESTION ===
query_embedding = openai.embeddings.create(
    input=[user_question],
    model=EMBEDDING_MODEL
).data[0].embedding

# === FIND TOP K MATCHES ===
k = 2
D, I = index.search(np.array([query_embedding]).astype("float32"), k)
matches = [metadata[i] for i in I[0]]

# === BUILD PROMPT ===
context = "\n\n".join(f"{m['section']}: {m['text']}" for m in matches)
final_prompt = f"""### Context:
{context}

### Question:
{user_question}
"""

# === SEND TO LLM ===
response = openai.chat.completions.create(
    model=CHAT_MODEL,
    messages=[
        {"role": "system", "content": "You are an expert in federal acquisition regulations."},
        {"role": "user", "content": final_prompt}
    ]
)

# === SHOW OUTPUT ===
print("\n🔎 Answer:\n")
print(response.choices[0].message.content)
