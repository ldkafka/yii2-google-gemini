# Yii2 Google Gemini Component

A Yii2 component for integrating Google Gemini AI with conversation history management using Yii's cache system.

## Features

- 🚀 Easy integration with Google Gemini AI API
- 💬 Conversation history management using Yii cache (Redis, File, Database, etc.)
- 🎯 Support for multi-turn chat sessions with context awareness
- ⚙️ Configurable generation parameters (temperature, topK, topP, maxOutputTokens)
- 🔍 Built-in error handling and Yii logging integration
- 🔌 Proxy access to all underlying Gemini client methods
- 📦 Zero file/redis dependencies - uses only Yii cache components

## Requirements

- PHP >= 8.0
- Yii2 >= 2.0.14
- google-gemini-php/client >= 2.6.0
- Yii cache component configured (e.g., Redis, FileCache, DbCache)

## Installation

### Method 1: Via Composer Command Line

```bash
composer require ldkafka/yii2-google-gemini
```

### Method 2: Add to Your Yii2 Project's composer.json

Add the package to your `composer.json` file:

```json
{
    "require": {
        "ldkafka/yii2-google-gemini": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ldkafka/yii2-google-gemini.git"
        }
    ]
}
```

Then run:

```bash
composer update ldkafka/yii2-google-gemini
```

**Note:** Once the package is published on Packagist, you can remove the `repositories` section and use a stable version number instead of `dev-main`.

## Configuration

### Basic Configuration

Add the component to your Yii2 application configuration (e.g., `common/config/main.php` or `console/config/main.php`):

```php
'components' => [
    'gemini' => [
        'class' => 'ldkafka\gemini\Gemini',
        'config' => [
            'apiKey' => 'YOUR_GOOGLE_GEMINI_API_KEY',
            'baseUrl' => 'https://generativelanguage.googleapis.com/v1/', // Recommended: use v1 API
        ],
        'cacheComponent' => 'cache', // Uses Yii::$app->cache by default
        'cacheTtl' => 3600, // Cache conversation history for 1 hour
    ],
],
```

### Advanced Configuration

```php
'components' => [
    'gemini' => [
        'class' => 'ldkafka\gemini\Gemini',
        'config' => [
            'apiKey' => 'YOUR_GOOGLE_GEMINI_API_KEY',
            'baseUrl' => 'https://generativelanguage.googleapis.com/v1/',
            // Optional: Custom HTTP headers
            'headers' => [
                'X-Custom-Header' => 'value',
            ],
            // Optional: Custom query parameters
            'query' => [
                'param' => 'value',
            ],
        ],
        'cacheComponent' => 'cache', // Or 'redis', 'fileCache', etc.
        'cacheTtl' => 7200, // 2 hours
        'cachePrefix' => 'my_app_gemini_', // Custom cache key prefix
        'defaultGenerationOptions' => [
            'temperature' => 0.7,      // Creativity level (0.0-2.0)
            'topK' => 40,              // Token selection limit
            'topP' => 0.95,            // Nucleus sampling threshold
            'maxOutputTokens' => 2048, // Maximum response length
        ],
    ],
],
```

### Configuration Options

#### Required

- **`config`** (array): Configuration for the Gemini client
  - **`apiKey`** (string): Your Google Gemini API key
  - **`baseUrl`** (string, optional): API endpoint URL. Default: v1beta. **Recommended:** `https://generativelanguage.googleapis.com/v1/` to avoid model compatibility issues.

#### Optional

- **`cacheComponent`** (string): Yii cache component ID. Default: `'cache'`
- **`cacheTtl`** (int): Cache time-to-live in seconds. Default: `3600` (1 hour)
- **`cachePrefix`** (string): Cache key prefix. Default: `'gemini_chat_'`
- **`conversationId`** (string|null): Auto-load conversation history on init
- **`defaultGenerationOptions`** (array): Default AI generation parameters
  - `temperature` (float): Controls randomness (0.0 = deterministic, 2.0 = very creative)
  - `topK` (int): Limits vocabulary selection to top K candidates
  - `topP` (float): Nucleus sampling threshold (0.0-1.0)
  - `maxOutputTokens` (int): Maximum response length in tokens
  - `candidateCount` (int): Number of response variations to generate
  - `stopSequences` (array): Strings that stop generation when encountered

## Usage

### Basic Chat

```php
// Single message without conversation history
$gemini = Yii::$app->gemini;
$response = $gemini->chat('gemini-2.0-flash-exp', 'What is PHP?');

if ($response['success']) {
    echo $response['response']; // "PHP is a popular server-side scripting language..."
} else {
    echo "Error: " . $response['error'];
}
```

### Conversation with History

```php
$conversationId = 'user_' . Yii::$app->user->id . '_chat';

// First message
$response1 = $gemini->chat(
    'gemini-2.0-flash-exp',
    'My name is Alice and I live in Paris',
    $conversationId
);

// Follow-up message (maintains context from previous message)
$response2 = $gemini->chat(
    'gemini-2.0-flash-exp',
    'What city do I live in?',
    $conversationId
);
// Response: "You live in Paris."

// Another follow-up
$response3 = $gemini->chat(
    'gemini-2.0-flash-exp',
    'What is my name?',
    $conversationId
);
// Response: "Your name is Alice."
```

### Console Command Example

```php
// console/controllers/TestController.php
public function actionGemini($message = null, $conversationId = null)
{
    if (!$message) {
        echo "Usage: php yii test/gemini \"Your message\" [conversationId]\n";
        return ExitCode::UNSPECIFIED_ERROR;
    }

    try {
        $gemini = new \ldkafka\gemini\Gemini([
            'config' => [
                'apiKey' => Yii::$app->params['gemini_api_key'],
                'baseUrl' => 'https://generativelanguage.googleapis.com/v1/',
            ],
            'cacheComponent' => 'cache',
            'cacheTtl' => 3600,
            'defaultGenerationOptions' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ],
        ]);

        $response = $gemini->chat('gemini-2.0-flash-exp', $message, $conversationId);

        if ($response['success']) {
            echo "Response: " . $response['response'] . "\n";
            if ($conversationId) {
                echo "Conversation ID: " . $conversationId . "\n";
            }
            return ExitCode::OK;
        } else {
            echo "Error: " . $response['error'] . "\n";
            return ExitCode::UNSPECIFIED_ERROR;
        }
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
```

Run it:
```bash
php yii test/gemini "Hello, how are you?" conv_123
```

### Managing Conversations

```php
$gemini = Yii::$app->gemini;
$conversationId = 'user_session_123';

// Load existing conversation history
$history = $gemini->loadConversation($conversationId);
if ($history) {
    echo "Found " . count($history) . " messages in history\n";
    foreach ($history as $message) {
        echo $message['role'] . ": " . implode("\n", $message['parts']) . "\n";
    }
}

// Manually save conversation
$customHistory = [
    ['role' => 'user', 'parts' => ['Hello']],
    ['role' => 'model', 'parts' => ['Hi there!']],
];
$gemini->saveConversation($conversationId, $customHistory);

// Clear conversation by saving empty array
$gemini->saveConversation($conversationId, []);
```

### Direct Access to Gemini Client

Access any method from the underlying [google-gemini-php/client](https://github.com/google-gemini/generative-ai-php):

```php
$gemini = Yii::$app->gemini;

// Get the client directly
$client = $gemini->getClient();

// Or use magic method proxy
$model = $gemini->generativeModel('gemini-2.0-flash-exp');
$response = $model->generateContent('Write a haiku about PHP');
echo $response->text();
```

### Custom Generation Config Per Request

```php
$response = $gemini->chat(
    'gemini-2.0-flash-exp',
    'Write a creative story about AI',
    null, // No conversation history
);

// Note: Per-request config overrides are handled via buildGenerationConfig()
// For now, set defaultGenerationOptions or use the client directly for advanced use cases
```

## Available Models

Common Gemini models (as of 2025):

- **`gemini-2.0-flash-exp`** - Latest Gemini 2.0 Flash (experimental, fastest, recommended)
- **`gemini-1.5-pro`** - Gemini 1.5 Pro (balanced performance)
- **`gemini-1.5-flash`** - Gemini 1.5 Flash (fast responses)

**Important:** Use `baseUrl: 'https://generativelanguage.googleapis.com/v1/'` to ensure compatibility with newer models.

## Error Handling

The component includes built-in error handling and Yii logging:

```php
try {
    $response = Yii::$app->gemini->chat('gemini-2.0-flash-exp', 'Your message');
    
    if ($response['success']) {
        echo $response['response'];
    } else {
        // Handle graceful errors (e.g., API rate limits, invalid input)
        Yii::error('Gemini chat error: ' . $response['error']);
        echo "Sorry, I couldn't process your request: " . $response['error'];
    }
} catch (\RuntimeException $e) {
    // Handle critical errors (e.g., missing API key, network issues)
    Yii::error('Gemini exception: ' . $e->getMessage());
    echo "An unexpected error occurred. Please try again later.";
}
```

Errors are automatically logged to your Yii2 application logs with appropriate severity levels.

## Cache Component Setup

The component requires a configured Yii cache component. Examples:

### Redis Cache (Recommended)

```php
'components' => [
    'cache' => [
        'class' => 'yii\redis\Cache',
        'redis' => [
            'hostname' => 'localhost',
            'port' => 6379,
            'database' => 0,
        ],
    ],
],
```

### File Cache

```php
'components' => [
    'cache' => [
        'class' => 'yii\caching\FileCache',
        'cachePath' => '@runtime/cache',
    ],
],
```

### Database Cache

```php
'components' => [
    'cache' => [
        'class' => 'yii\caching\DbCache',
        'cacheTable' => 'cache',
    ],
],
```

## Response Format

The `chat()` method returns an array with the following structure:

```php
[
    'success' => true|false,        // Whether the request succeeded
    'response' => 'Model response'|null,  // The AI's text response
    'history' => [                  // Updated conversation history
        ['role' => 'user', 'parts' => ['message']],
        ['role' => 'model', 'parts' => ['response']],
        // ...
    ],
    'error' => 'Error message'|null // Error description if failed
]
```

## Conversation History Format

Conversation history is stored as an array of message objects:

```php
[
    [
        'role' => 'user',           // or 'model'
        'parts' => ['Message text'] // Array of message parts
    ],
    [
        'role' => 'model',
        'parts' => ['Response text']
    ],
    // ...
]
```

## Troubleshooting

### "models/gemini-1.5-flash is not found for API version v1beta"

**Solution:** Set `baseUrl` to use the v1 API instead of v1beta:

```php
'config' => [
    'apiKey' => 'YOUR_API_KEY',
    'baseUrl' => 'https://generativelanguage.googleapis.com/v1/',
],
```

### "No cache component available for Gemini conversation storage"

**Solution:** Ensure you have a cache component configured in your Yii application:

```php
'components' => [
    'cache' => [
        'class' => 'yii\caching\FileCache',
    ],
],
```

### Cache not persisting between requests

**Solution:** Check your cache component configuration and TTL settings. FileCache may require write permissions to the cache directory.

## License

BSD-3-Clause License. See [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/ldkafka/yii2-google-gemini).

## Changelog

### 1.0.0 (2025-11-06)

- Initial release
- Yii cache-based conversation storage
- Support for Google Gemini 2.0 models
- Comprehensive error handling and logging
- Full PHPDoc documentation
