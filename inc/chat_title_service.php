<?php
declare(strict_types=1);

class ChatTitleService
{
    public function generate(string $userMessage, string $assistantReply, string $configKey, string $chatId): ?string
    {
        $messages = $this->buildPrompt($userMessage, $assistantReply);
        $activeConfig = load_configuration($configKey, true);

        if (!$activeConfig) {
            error_log('ChatTitleService: configuration not found for ' . $configKey);
            return null;
        }

        try {
            $response = call_azure_api($activeConfig, $chatId, $messages);
        } catch (Throwable $e) {
            error_log('ChatTitleService: exception calling Azure API: ' . $e->getMessage());
            return null;
        }

        $decoded  = json_decode($response, true);

        if (!is_array($decoded) || !empty($decoded['error'])) {
            error_log('ChatTitleService: title generation failed: ' . substr($response, 0, 400));
            return null;
        }

        $content = trim($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return null;
        }

        $content = $this->truncateWords($content, 6);
        return substr($content, 0, 254);
    }

    private function buildPrompt(string $userMessage, string $assistantReply): array
    {
        return [
            [
                'role'    => 'system',
                'content' => 'You are an AI assistant that creates concise, friendly title summaries for chats. '
                    . 'Use no more than 5 words. Never include code or punctuation. Never include mathematical '
                    . 'notation. Only use words and if needed, numbers.'
            ],
            ['role' => 'user',      'content' => $this->truncateWords($userMessage, 300)],
            ['role' => 'assistant', 'content' => $this->truncateWords($assistantReply, 300)],
            [
                'role'    => 'user',
                'content' => 'Please create a concise, friendly title summarizing this chat. Use no more than 5 words. '
                    . 'Never include code or punctuation. Never include mathematical notation. Only use words and if '
                    . 'needed, numbers.'
            ],
        ];
    }

    private function truncateWords(string $text, int $limit): string
    {
        $words = preg_split('/\s+/', trim($text));
        if ($words === false) {
            return $text;
        }
        if (count($words) <= $limit) {
            return $text;
        }
        return implode(' ', array_slice($words, 0, $limit));
    }
}
