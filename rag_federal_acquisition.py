#!/usr/bin/env python3
import os
import json
import faiss
import numpy as np
import openai

# === CONFIG ===
AZURE_OPENAI_ENDPOINT = "https://nhlbi-chat.openai.azure.com/"
AZURE_OPENAI_API_KEY = "c766f3be8420471dabccac63c2f75d8f"
EMBEDDING_MODEL = "NHLBI-Chat-workflow-text-embedding-3-large"
CHAT_MODEL = "NHLBI-Chat-gpt-4o"
API_VERSION = "2024-12-01-preview"

# === INIT OPENAI FOR AZURE ===

openai.api_type = "azure"
openai.api_version = "2024-12-01-preview"
openai.api_key = "c766f3be8420471dabccac63c2f75d8f"
openai.azure_endpoint = "https://nhlbi-chat.openai.azure.com"


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

