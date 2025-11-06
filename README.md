# Yii2 Google Gemini Component

A Yii2 component for integrating Google Gemini AI with conversation history management and persistent chat sessions.

## Features

- Easy integration with Google Gemini AI API
- Conversation history management with Redis/Database/File storage
- Support for multi-turn chat sessions
- Generation configuration (temperature, topK, topP, maxOutputTokens)
- Error handling and logging
- Proxy method to access all Gemini client methods

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

Add the component to your Yii2 application configuration:

```php
'components' => [
    'gemini' => [
        'class' => 'ldkafka\gemini\Gemini',
        'apiKey' => 'YOUR_GOOGLE_GEMINI_API_KEY',
        'conversationStoragePath' => '@runtime/gemini_conversations',
        'conversationStorageType' => 'file', // 'file', 'redis', or 'database'
        // Optional Redis configuration if using Redis storage
        // 'redis' => 'redis',
    ],
],
```

### Configuration Options

- `apiKey` (string, required): Your Google Gemini API key
- `conversationStoragePath` (string, optional): Path for file-based conversation storage
- `conversationStorageType` (string, optional): Storage type - 'file', 'redis', or 'database'
- `redis` (string|array, optional): Redis component name or configuration

## Usage

### Basic Chat

```php
// Single message
$response = Yii::$app->gemini->chat('Hello, how are you?');
echo $response;
```

### Conversation with History

```php
// Start or continue a conversation
$conversationId = 'user_123_chat';

// First message
$response1 = Yii::$app->gemini->chat(
    'What is the capital of France?',
    $conversationId
);

// Follow-up message (maintains context)
$response2 = Yii::$app->gemini->chat(
    'What is its population?',
    $conversationId
);

// The AI will understand "its" refers to Paris from the previous context
```

### Custom Generation Configuration

```php
$config = [
    'temperature' => 0.7,
    'topK' => 40,
    'topP' => 0.95,
    'maxOutputTokens' => 1024,
];

$response = Yii::$app->gemini->chat(
    'Write a creative story',
    null,
    $config
);
```

### Managing Conversations

```php
// Load conversation history
$history = Yii::$app->gemini->loadConversation($conversationId);

// Clear conversation history
Yii::$app->gemini->clearConversation($conversationId);

// Delete conversation
Yii::$app->gemini->deleteConversation($conversationId);
```

### Direct Access to Gemini Client

Access any method from the underlying Google Gemini client:

```php
// Get the client directly
$client = Yii::$app->gemini->getClient();

// Or use magic method proxy
$model = Yii::$app->gemini->generativeModel('gemini-1.5-flash');
$response = $model->generateContent('Your prompt here');
```

## Error Handling

The component includes built-in error handling and logging:

```php
try {
    $response = Yii::$app->gemini->chat('Your message');
} catch (\RuntimeException $e) {
    // Handle Gemini-specific errors
    Yii::error('Gemini error: ' . $e->getMessage());
}
```

Errors are automatically logged to your Yii2 application logs.

## Storage Types

### File Storage (Default)

Stores conversations as JSON files in the specified path:

```php
'conversationStoragePath' => '@runtime/gemini_conversations',
'conversationStorageType' => 'file',
```

### Redis Storage

Requires a configured Redis component:

```php
'conversationStorageType' => 'redis',
'redis' => 'redis', // Your Redis component name
```

### Database Storage

Stores conversations in your configured database:

```php
'conversationStorageType' => 'database',
```

## Requirements

- PHP >= 8.0
- Yii2 >= 2.0.14
- google-gemini-php/client >= 2.6.0

## License

BSD-3-Clause License. See [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues, questions, or contributions, please visit the [GitHub repository](https://github.com/ldkafka/yii2-google-gemini).
