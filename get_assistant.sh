#!/usr/bin/env bash
CONFIG_PATH="${CHAT_CONFIG_PATH:-/etc/apps/chatdev_config.ini}"
AZURE_ENDPOINT=$(php -r '
$path = getenv("CONFIG_PATH") ?: "/etc/apps/chatdev_config.ini";
$parser = parse_ini_file($path, true);
$default = $parser["azure"]["default"] ?? "";
if (!$default || empty($parser[$default]["url"]) || empty($parser[$default]["api_key"])) {
    fwrite(STDERR, "Config missing Azure info\n");
    exit(1);
}
echo rtrim($parser[$default]["url"], "/"), "\n", $parser[$default]["api_key"];
' CONFIG_PATH="$CONFIG_PATH") || { echo "Failed to read Azure credentials from $CONFIG_PATH" >&2; exit 1; }
AZURE_OPENAI_ENDPOINT=$(echo "$AZURE_ENDPOINT" | sed -n '1p')
AZURE_OPENAI_KEY=$(echo "$AZURE_ENDPOINT" | sed -n '2p')

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
