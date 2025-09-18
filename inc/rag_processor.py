#!/usr/bin/env python3
import os
import json
import faiss
import numpy as np
import openai
import sys
import argparse # Use argparse for cleaner argument handling

# === CONFIG ===
AZURE_OPENAI_ENDPOINT = "https://nhlbi-chat.openai.azure.com/"
AZURE_OPENAI_API_KEY = "c766f3be8420471dabccac63c2f75d8f"
EMBEDDING_MODEL = "NHLBI-Chat-workflow-text-embedding-3-large"
CHAT_MODEL = "NHLBI-Chat-gpt-4o"
API_VERSION = "2024-12-01-preview"

# === INIT OPENAI FOR AZURE ===
# Ensure these match the values used for embedding
try:
    openai.api_type = "azure"
    openai.api_version = API_VERSION
    openai.api_key = AZURE_OPENAI_API_KEY
    openai.azure_endpoint = AZURE_OPENAI_ENDPOINT
except Exception as e:
    print(json.dumps({"error": f"Failed to initialize OpenAI client: {e}"}))
    sys.exit(1)


# === SAMPLE DOCUMENT ===
DOCUMENTS = [
    {"section": "Sole Source", "text": "As of March, 2025, Sole-source contracts may be used when only one source is available due to urgency or unique capability - in the originating state or district."},
    {"section": "Competition", "text": "Federal agencies are generally required to use full and open competition when awarding contracts."},
    {"section": "Small Business", "text": "Contracts may be set aside for small businesses if certain criteria are met under FAR Subpart 19."}
]

# === Function to handle RAG processing ===
def get_augmented_prompt(user_question: str, k: int = 2) -> dict:
    """
    Performs RAG steps: Embed documents, build index, embed question, search, build prompt.
    Returns a dictionary containing the augmented prompt or an error message.
    """
    try:
        # === CREATE EMBEDDINGS AND BUILD FAISS INDEX ===
        # This is inefficient to do on every call.
        # Future: Load pre-computed embeddings and index.
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

        if not embeddings:
             return {"error": "No documents found or failed to create embeddings."}

        dimension = len(embeddings[0])
        index = faiss.IndexFlatL2(dimension)
        index.add(np.array(embeddings).astype("float32"))

        # === EMBED THE QUESTION ===
        query_embedding_response = openai.embeddings.create(
            input=[user_question],
            model=EMBEDDING_MODEL
        )
        query_embedding = query_embedding_response.data[0].embedding

        # === FIND TOP K MATCHES ===
        D, I = index.search(np.array([query_embedding]).astype("float32"), k)
        matches = [metadata[i] for i in I[0]]

        # === BUILD PROMPT CONTEXT ===
        context = "\n\n".join(f"Source: FAR Section {m['section']}\nContent: {m['text']}" for m in matches) # Added labels for clarity

        # === CONSTRUCT AUGMENTED PROMPT ===
        # This is the final output string this script will provide
        final_prompt = f"""Based on the following excerpts from the Federal Acquisition Regulation (FAR), answer the user's question.

### Relevant FAR Context:
{context}

### User Question:
{user_question}

### Answer:
""" # Added instruction and structure

        return {"augmented_prompt": final_prompt}

    except openai.APIError as e:
        # Handle API error here, e.g. retry or log
        return {"error": f"Azure OpenAI API returned an API Error: {e}"}
    except openai.AuthenticationError as e:
        return {"error": f"Azure OpenAI API authentication failed: {e}"}
    except openai.RateLimitError as e:
        return {"error": f"Azure OpenAI API request exceeded rate limit: {e}"}
    except Exception as e:
        # Catch any other exceptions
        return {"error": f"An unexpected error occurred during RAG processing: {e}"}


# === MAIN EXECUTION BLOCK ===
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Perform RAG lookup for FAR questions.")
    parser.add_argument("user_question", type=str, help="The user's question about the FAR.")
    # Optional: Add arguments for 'k', models, etc. if needed later
    # parser.add_argument("-k", type=int, default=2, help="Number of documents to retrieve.")

    args = parser.parse_args()

    # Call the RAG function
    result = get_augmented_prompt(args.user_question) # Using k=2 default

    # Print the result as JSON to stdout
    print(json.dumps(result))
