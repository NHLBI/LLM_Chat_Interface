# token_counter.py

import sys
import json
import tiktoken

def num_tokens_from_string(string: str, encoding_name: str) -> int:
    """
    Returns the number of tokens in a text string.
    """
    encoding = tiktoken.get_encoding(encoding_name)
    num_tokens = len(encoding.encode(string))
    return num_tokens

def num_tokens_from_messages(messages, model: str) -> int:
    """
    Returns the number of tokens used by a list of messages for chat models.
    """
    try:
        encoding = tiktoken.encoding_for_model(model)
    except KeyError:
        print(f"Warning: model {model} not found. Using cl100k_base encoding.")
        encoding = tiktoken.get_encoding("cl100k_base")

    if model.startswith("gpt-3.5-turbo") or model.startswith("gpt-4"):
        tokens_per_message = 4  # Every message follows <|start|>{role/name}\n{content}<|end|>
        tokens_per_name = -1  # If there's a name, the role is omitted
    elif model.startswith("gpt-4o"):
        tokens_per_message = 3
        tokens_per_name = 1
    else:
        raise NotImplementedError(f"Token counting not implemented for model {model}.")

    num_tokens = 0
    for message in messages:
        num_tokens += tokens_per_message
        for key, value in message.items():
            num_tokens += len(encoding.encode(value))
            if key == "name":
                num_tokens += tokens_per_name
    num_tokens += 3  # Every reply is primed with <|start|>assistant<|message|>
    return num_tokens

def main():
    if len(sys.argv) < 4:
        print("Usage: python token_counter.py <mode> <model/encoding_name> <text_or_messages_json>")
        sys.exit(1)
    
    mode = sys.argv[1]  # Should be 'text' or 'messages'
    model_or_encoding = sys.argv[2]
    input_data = sys.argv[3]

    if mode == 'text':
        text = input_data
        num_tokens = num_tokens_from_string(text, model_or_encoding)
    elif mode == 'messages':
        messages_json = input_data
        messages = json.loads(messages_json)
        num_tokens = num_tokens_from_messages(messages, model_or_encoding)
    else:
        print("Invalid mode. Use 'text' or 'messages'.")
        sys.exit(1)

    print(num_tokens)

if __name__ == "__main__":
    main()

