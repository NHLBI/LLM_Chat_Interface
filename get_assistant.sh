#!/usr/bin/env bash
# ----- fill in your secrets first -----
export AZURE_OPENAI_ENDPOINT="https://nhlbi-chat.openai.azure.com"
export AZURE_OPENAI_KEY="c766f3be8420471dabccac63c2f75d8f"
# --------------------------------------

curl -sS -X POST \
  "$AZURE_OPENAI_ENDPOINT/openai/assistants?api-version=2024-08-01-preview" \
  -H "Content-Type: application/json" \
  -H "api-key: $AZURE_OPENAI_KEY" \
  -d '{
        "name": "NHLBI-DocWriter",
        "model": "NHLBI-Chat-gpt-4o",
        "instructions": "Answer normally. When the user asks for a file, use python-docx, openpyxl or python-pptx inside Code Interpreter and save the file.",
        "tools": [ { "type": "code_interpreter" } ]
      }'

