# Yii2 Google Gemini Component (v2.0.0)

Native, strongly-typed Yii2 component for the Google Gemini REST API. No external SDKs – only `yii\\httpclient`. Provides generation (text & multimodal), streaming, embeddings, Files API, token counting, and flexible caching strategies.

## What’s New in 2.0.0

* Rebuilt on pure REST calls (no Gemini SDK)
* Added multimodal inline data handling
* Added SSE streaming helper
* Full embeddings single + batch support
* Files API wiring (simplified upload flow)
* Model discovery (list + get)
* Uniform response shape & error handling
* Client & server caching patterns
* Strict typing, `final` class, consistent helper methods

## Feature Summary

| Area | Capabilities |
|------|--------------|
| Generation | Text, multimodal (image/audio/video/document via inline or file references) |
| Streaming | SSE incremental output with user callback |
| Caching | `none`, `client` (Yii cache history), `server` (Gemini CachedContent) |
| Embeddings | Single + batch embeddings for RAG / similarity |
| Files | Upload, list, get, delete (simplified direct PUT) |
| Models | Enumerate models, inspect limits/capabilities |
| Tokens | Pre-flight token counting for cost estimation |
| Helpers | `extractText`, `getFinishReason`, `getUsageMetadata` |

## Requirements

- PHP >= 8.0
- Yii2 >= 2.0.40
- yiisoft/yii2-httpclient

## Installation

```bash
composer require ldkafka/yii2-google-gemini
```

## Quick Start

### Basic Configuration

```php
'components' => [
    'gemini' => [
        'class' => 'ldkafka\gemini\Gemini',
        'apiKey' => 'YOUR_GEMINI_API_KEY',
        'generationConfig' => [
            'temperature' => 0.7,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
        ],
    ],
],
```

### Simple Text Generation

```php
$gemini = Yii::$app->gemini;
$resp = $gemini->generateContent('gemini-2.5-flash', 'Explain quantum computing');

if ($resp['ok']) {
    echo $gemini->extractText($resp['data']);
}
```

## Usage Examples

### 1. Basic Text Generation

```php
$resp = $gemini->generateContent('gemini-2.5-flash', 'What is PHP?');

if ($resp['ok']) {
    $text = $gemini->extractText($resp['data']);
    $usage = $gemini->getUsageMetadata($resp['data']);
    echo "Response: $text\n";
    echo "Tokens used: {$usage['totalTokenCount']}\n";
}
```

### 2. Streaming Responses

```php
$gemini->streamGenerateContent('gemini-2.5-flash', 'Write a short story', function($chunk) {
    if ($text = $chunk['candidates'][0]['content']['parts'][0]['text'] ?? null) {
        echo $text;
        flush();
    }
});
```

### 3. Multimodal (Text + Image)

```php
$content = [[
    'parts' => [
        ['text' => 'What is in this image?'],
        ['inline_data' => [
            'mime_type' => 'image/jpeg',
            'data' => base64_encode(file_get_contents('/path/to/image.jpg'))
        ]]
    ],
    'role' => 'user'
]];

$resp = $gemini->generateContent('gemini-2.5-flash', $content);
```

### 4. Client-side Conversation Caching

```php
$gemini->cacheType = 'client';
$gemini->cacheTtl = 3600;

// First message
$resp = $gemini->chat('gemini-2.5-flash', 'My name is Alice', 'user123');

// Follow-up (remembers context)
$resp = $gemini->chat('gemini-2.5-flash', 'What is my name?', 'user123');
// Response: "Your name is Alice."
```

### 5. Server-side Context Caching (Advanced)

```php
$gemini->cacheType = 'server';

// Create cache with system instruction (requires 32k+ tokens)
$cacheName = $gemini->createServerCache(
    'gemini-2.5-flash',
    'assistant-id',
    'You are a helpful travel assistant. [... long system instruction ...]',
    3600
);

// Use cached context
$resp = $gemini->chatServer('gemini-2.5-flash', 'Best beaches in Sydney?', 'assistant-id');
```

### 6. System Instructions

```php
$gemini->systemInstruction = [
    'parts' => [['text' => 'You are a helpful coding assistant who explains concepts simply.']]
];

$resp = $gemini->generateContent('gemini-2.5-flash', 'Explain recursion');
```

### 7. File Uploads

```php
// Upload a large video file
$resp = $gemini->uploadFile('/path/to/video.mp4', 'My Video', 'video/mp4');
$fileUri = $resp['data']['file']['uri'];

// Use in generation
$content = [[
    'parts' => [
        ['text' => 'Summarize this video'],
        ['file_data' => [
            'file_uri' => $fileUri,
            'mime_type' => 'video/mp4'
        ]]
    ]
]];
$resp = $gemini->generateContent('gemini-2.5-flash', $content);

// List uploaded files
$files = $gemini->listFiles();

// Delete file
$gemini->deleteFile('files/abc123');
```

### 8. Embeddings

```php
// Single embedding
$resp = $gemini->embedContent(
    'text-embedding-004',
    'Hello world',
    'RETRIEVAL_QUERY'
);
$embedding = $resp['data']['embedding']['values'];

// Batch embeddings
$requests = [
    [
        'content' => ['parts' => [['text' => 'Document 1']]],
        'taskType' => 'RETRIEVAL_DOCUMENT'
    ],
    [
        'content' => ['parts' => [['text' => 'Document 2']]],
        'taskType' => 'RETRIEVAL_DOCUMENT'
    ],
];
$resp = $gemini->batchEmbedContents('text-embedding-004', $requests);
```

### 9. Token Counting

```php
$tokens = $gemini->countTokens('gemini-2.5-flash', 'Your prompt text here');
echo "This prompt will use approximately $tokens tokens\n";
```

### 10. Model Discovery

```php
// List all available models
$models = $gemini->listModels();
foreach ($models['data']['models'] as $model) {
    echo "{$model['name']}: {$model['displayName']}\n";
}

// Get specific model details
$model = $gemini->getModel('gemini-2.5-flash');
echo "Context window: {$model['data']['inputTokenLimit']} tokens\n";
```

## Caching Modes Deep Dive

| Mode | Purpose | Storage | Pros | Cons |
|------|---------|---------|------|------|
| `none` | Stateless requests | None | Simplicity | No memory of prior turns |
| `client` | Short/medium chats | Yii cache (`gem_chat_<id>`) | Fast, light, adjustable TTL | History grows; prune for very long sessions |
| `server` | Large domain context | Gemini CachedContent | Huge reusable context on provider side | Requires ~32K+ tokens; creation often fails if too small |

Server cache creation requires a very large system instruction document. Use `countTokens()` before attempting `createServerCache()`.

## Console Commands

Example test commands in `console/controllers/TestController.php`:

```bash
# Stateless generation
php yii test/gemini-none "What is the capital of France?"

# Client-side caching (conversation)
php yii test/gemini-client

# Server-side caching
php yii test/gemini-server "Tell me about Sydney beaches"

# Clear cache
php yii test/gemini-client test-chat 1
```

## Configuration Options

### Component Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `apiKey` | string | *required* | Your Gemini API key ([Get one](https://aistudio.google.com/apikey)) |
| `baseUrl` | string | `https://generativelanguage.googleapis.com/v1/` | API base URL |
| `generationConfig` | array | `[]` | Default generation parameters |
| `safetySettings` | array | `[]` | Content safety filters |
| `systemInstruction` | array\|null | `null` | Default system instruction |
| `cacheType` | string | `'none'` | Cache mode: `'none'`, `'client'`, `'server'` |
| `cacheTtl` | int | `3600` | Cache TTL in seconds |
| `cacheComponent` | string\|null | `'cache'` | Yii cache component name |
| `httpConfig` | array | `[]` | Custom HTTP client configuration |

### Generation Config Options

```php
'generationConfig' => [
    'temperature' => 0.7,          // 0.0-2.0, creativity level
    'topP' => 0.95,                // 0.0-1.0, nucleus sampling
    'topK' => 40,                  // Token selection limit
    'maxOutputTokens' => 2048,     // Max response length
    'stopSequences' => ['END'],    // Stop generation triggers
    'candidateCount' => 1,         // Number of responses
]
```

## Supported Models (Snapshot)

| Model | Description | Context Window |
|-------|-------------|----------------|
| `gemini-2.5-pro` | Most powerful thinking model | 2M tokens |
| `gemini-2.5-flash` | Balanced, fast, 1M context | 1M tokens |
| `gemini-2.5-flash-lite` | Fastest, cost-efficient | 1M tokens |
| `text-embedding-004` | Text embeddings for RAG | N/A |

See [Gemini Models Documentation](https://ai.google.dev/gemini-api/docs/models) for full list.

## Canonical Response Format

All methods return:

```php
[
    'ok' => true|false,      // Success status
    'status' => 200,         // HTTP status code
    'data' => [...],         // Response data
    'error' => null|string   // Error message if failed
]
```

### Helper Methods

```php
// Extract text from response
$text = $gemini->extractText($resp['data']);

// Get finish reason ('STOP', 'MAX_TOKENS', 'SAFETY', etc.)
$reason = $gemini->getFinishReason($resp['data']);

// Get usage metadata
$usage = $gemini->getUsageMetadata($resp['data']);
// ['promptTokenCount' => 10, 'candidatesTokenCount' => 50, 'totalTokenCount' => 60]
```

## Helper Methods

```php
$text   = $gemini->extractText($resp['data']);
$reason = $gemini->getFinishReason($resp['data']);
$usage  = $gemini->getUsageMetadata($resp['data']);
```

## Cache Modes (Summary)

### None (Stateless)

```php
$gemini->cacheType = 'none';
$resp = $gemini->generateContent('gemini-2.5-flash', 'Hello');
// Each request is independent
```

### Client (Local Conversation History)

```php
$gemini->cacheType = 'client';
$resp = $gemini->chat('gemini-2.5-flash', 'My name is Bob', 'user123');
$resp = $gemini->chat('gemini-2.5-flash', 'What is my name?', 'user123');
// Conversation stored in Yii cache component
```

### Server (Gemini Context Caching)

```php
$gemini->cacheType = 'server';
$cacheName = $gemini->createServerCache(
    'gemini-2.5-flash',
    'id',
    '[Large system instruction 32k+ tokens]',
    3600
);
$resp = $gemini->chatServer('gemini-2.5-flash', 'Question', 'id');
// System instruction cached on Google's servers
```

**Note:** Server caching requires minimum 32,000 tokens in cached content.

## Error Handling Pattern

```php
$resp = $gemini->generateContent('gemini-2.5-flash', 'Hello');

if (!$resp['ok']) {
    Yii::error("Gemini API error: {$resp['error']} (HTTP {$resp['status']})");
    
    // Common error codes:
    // 400 - Bad request (invalid parameters)
    // 401 - Invalid API key
    // 429 - Rate limit exceeded
    // 500 - Server error
}
```

## Advanced Usage

### Custom HTTP Configuration

```php
'gemini' => [
    'class' => 'ldkafka\gemini\Gemini',
    'apiKey' => 'YOUR_KEY',
    'httpConfig' => [
        'timeout' => 60,
        'transport' => 'yii\httpclient\CurlTransport',
    ],
],
```

### Multimodal with Multiple Images

```php
$content = [[
    'parts' => [
        ['text' => 'Compare these images'],
        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($image1)]],
        ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($image2)]],
    ]
]];
```

### Custom Safety Settings

```php
$gemini->safetySettings = [
    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
];
```

### Safety Settings Explained

`safetySettings` lets you tell Gemini which kinds of harmful content to filter and at what strictness. The value is an array of objects with a `category` and a `threshold`.

- Common categories: `HARM_CATEGORY_HARASSMENT`, `HARM_CATEGORY_HATE_SPEECH`, `HARM_CATEGORY_SEXUALLY_EXPLICIT`, `HARM_CATEGORY_DANGEROUS_CONTENT`, `HARM_CATEGORY_VIOLENCE`.
- Typical thresholds (in order of strictness): `BLOCK_NONE`, `BLOCK_LOW_AND_ABOVE`, `BLOCK_MEDIUM_AND_ABOVE`, `BLOCK_ONLY_HIGH`.

Example JSON payload as sent to the API:

```json
[
    { "category": "HARM_CATEGORY_HARASSMENT", "threshold": "BLOCK_MEDIUM_AND_ABOVE" },
    { "category": "HARM_CATEGORY_HATE_SPEECH", "threshold": "BLOCK_ONLY_HIGH" }
]
```

Notes:
- If `safetySettings` is empty, no custom filters are applied (provider defaults may still apply).
- You can mix categories with different thresholds.
- Overly strict settings can block benign answers; adjust to your domain’s tolerance.

## Testing

The package includes comprehensive test actions:

1. **actionGeminiNone** - Test stateless generation
2. **actionGeminiClient** - Test client-side conversation caching
3. **actionGeminiServer** - Test server-side context caching

## Troubleshooting

### "API key not configured"

Ensure your API key is set in the component configuration or params.

### "Failed to create server cache"

Server caching requires:
- Minimum 32,000 tokens in the cached content
- Supported model (gemini-2.5-flash, gemini-2.5-pro)
- System instruction or large document

Use client-side caching for shorter conversations.

### Streaming not working

Ensure your HTTP client supports Server-Sent Events (SSE). The default Yii2 HTTP client may need custom transport configuration.

## Production Notes

* Implement backoff + retry for `429` & transient `5xx` responses.
* Prune client cache histories when token counts get large (outside of scope for this base component).
* For server caching: build and store a domain knowledge base (e.g., large markdown/text corpus) and verify token count with `countTokens()`.
* Log latency and token usage: `Yii::info([...], 'gemini')` for observability.

## Links

- [Gemini API Documentation](https://ai.google.dev/gemini-api/docs)
- [Get API Key](https://aistudio.google.com/apikey)
- [API Reference](https://ai.google.dev/api)
- [Model Pricing](https://ai.google.dev/gemini-api/docs/pricing)

## License

BSD-3-Clause (matches class header).

## Support / Contributing

Open issues or PRs at: https://github.com/ldkafka/yii2-google-gemini

When reporting an issue, include:
1. PHP / Yii versions
2. Failing method and sample call
3. Full response array (mask secrets)
4. Expected vs actual behavior

---
Enjoy building with Gemini! Suggestions & improvements welcome.
