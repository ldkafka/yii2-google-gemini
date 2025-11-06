<?php
/**
 * @link https://github.com/ldkafka/yii2-google-gemini
 * @copyright Copyright (c) 2025 Lucian Kafka
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 */

/**
 * Gemini Component for Yii2
 * 
 * A Yii2 component wrapper for Google Gemini AI integration with conversation history 
 * management using Yii's cache component.
 * 
 * @package ldkafka\gemini
 * @version 1.0.0
 * @license BSD-3-Clause
 * 
 * @example Basic usage:
 * ```php
 * $gemini = new \ldkafka\gemini\Gemini([
 *     'config' => [
 *         'apiKey' => 'YOUR_API_KEY',
 *         'baseUrl' => 'https://generativelanguage.googleapis.com/v1/',
 *     ],
 *     'cacheComponent' => 'cache',
 *     'cacheTtl' => 3600,
 * ]);
 * 
 * $response = $gemini->chat('gemini-2.0-flash-exp', 'Hello!', 'conv_123');
 * echo $response['response'];
 * ```
 */

namespace ldkafka\gemini;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Gemini extends Component
{
    /** @var string Package version */
    public const VERSION = '1.0.0';
    
    /** @var string Default cache key prefix for conversation storage */
    public const CACHE_PREFIX = 'gemini_chat_';
    
    /** @var int Default cache TTL in seconds (1 hour) */
    public const DEFAULT_CACHE_TTL = 3600;
    
    /** @var string Role identifier for user messages */
    public const ROLE_USER = 'user';
    
    /** @var string Role identifier for model responses */
    public const ROLE_MODEL = 'model';

    /**
     * @var array Configuration for the Gemini client.
     * 
     * Required keys:
     * - apiKey: string - Google Gemini API key
     * 
     * Optional keys:
     * - baseUrl: string - API endpoint (default: v1beta, recommended: v1)
     * - headers: array - Custom HTTP headers
     * - query: array - Custom query parameters
     * - httpClient: object - Custom HTTP client instance
     * - factory: callable - Custom client factory function
     * 
     * @example
     * ```php
     * 'config' => [
     *     'apiKey' => 'AIzaSy...',
     *     'baseUrl' => 'https://generativelanguage.googleapis.com/v1/',
     * ]
     * ```
     */
    public array $config = [];
    
    /**
     * @var string|null Yii cache component ID for conversation storage.
     * Default: 'cache' - uses Yii::$app->cache
     */
    public ?string $cacheComponent = 'cache';
    
    /**
     * @var int Cache time-to-live in seconds for conversation history.
     * Default: 3600 (1 hour)
     */
    public int $cacheTtl = self::DEFAULT_CACHE_TTL;
    
    /**
     * @var string Cache key prefix for conversation storage.
     * Default: 'gemini_chat_'
     */
    public string $cachePrefix = self::CACHE_PREFIX;
    
    /**
     * @var string|null Default conversation ID for automatic history loading.
     * If set, conversation history will be loaded automatically during init().
     */
    public ?string $conversationId = null;
    
    /**
     * @var array In-memory conversation history buffer.
     * Structure: [['role' => 'user|model', 'parts' => [string]], ...]
     */
    public array $history = [];
    
    /**
     * @var array Default generation options applied to all chat requests.
     * 
     * Supported options:
     * - temperature: float (0.0-2.0) - Controls randomness (higher = more creative)
     * - topK: int - Limits token selection to top K candidates
     * - topP: float (0.0-1.0) - Nucleus sampling threshold
     * - maxOutputTokens: int - Maximum response length
     * - candidateCount: int - Number of response candidates to generate
     * - stopSequences: array - Strings that stop generation when encountered
     * 
     * @example
     * ```php
     * 'defaultGenerationOptions' => [
     *     'temperature' => 0.7,
     *     'topK' => 40,
     *     'topP' => 0.95,
     *     'maxOutputTokens' => 2048,
     * ]
     * ```
     */
    public array $defaultGenerationOptions = [];

    /**
     * @var mixed|null Cached Gemini client instance (lazy-loaded).
     */
    private $_client = null;

    /**
     * Initialize the Gemini component.
     * 
     * Validates configuration and optionally loads conversation history
     * if conversationId is set.
     * 
     * @throws InvalidConfigException if config array is not provided
     */
    public function init(): void
    {
        parent::init();

        if (empty($this->config) || !is_array($this->config)) {
            throw new InvalidConfigException('`config` must be provided for the Gemini component.');
        }

        if (!isset($this->config['apiKey']) && !isset($this->config['credentials'])) {
            Yii::warning('Gemini component configured without explicit apiKey/credentials. Ensure client can be created externally.', __METHOD__);
        }

        if ($this->conversationId !== null) {
            $loaded = $this->loadConversation($this->conversationId);
            if (is_array($loaded)) {
                $this->history = $loaded;
            }
        }
    }

    /**
     * Get or create the Gemini client instance (lazy-loaded).
     * 
     * @return mixed The google-gemini-php client instance
     */
    public function getClient()
    {
        if ($this->_client === null) {
            $this->_client = $this->createClient();
        }
        return $this->_client;
    }

    /**
     * Create a new Gemini client instance using configuration.
     * 
     * Supports multiple client creation strategies:
     * 1. Custom factory function (if config['factory'] is callable)
     * 2. Direct Gemini::client() static method (if available)
     * 3. Gemini\Factory builder pattern (recommended)
     * 
     * @return mixed The created client instance
     * @throws \RuntimeException if client cannot be created
     */
    protected function createClient()
    {
        try {
            if (isset($this->config['factory']) && is_callable($this->config['factory'])) {
                return call_user_func($this->config['factory'], $this->config);
            }

            if (class_exists('\\Gemini')) {
                if (!empty($this->config['apiKey'])) {
                    return \Gemini::client((string)$this->config['apiKey']);
                }
            }

            if (class_exists('\\Gemini\\Factory')) {
                $factory = new \Gemini\Factory();
                if (!empty($this->config['apiKey'])) {
                    $factory->withApiKey((string)$this->config['apiKey']);
                }
                if (!empty($this->config['baseUrl'])) {
                    $factory->withBaseUrl((string)$this->config['baseUrl']);
                }
                if (!empty($this->config['headers']) && is_array($this->config['headers'])) {
                    foreach ($this->config['headers'] as $k => $v) {
                        $factory->withHttpHeader((string)$k, (string)$v);
                    }
                }
                if (!empty($this->config['query']) && is_array($this->config['query'])) {
                    foreach ($this->config['query'] as $k => $v) {
                        $factory->withQueryParam((string)$k, (string)$v);
                    }
                }
                if (!empty($this->config['httpClient'])) {
                    $factory->withHttpClient($this->config['httpClient']);
                }
                return $factory->make();
            }

            throw new \RuntimeException('Gemini client classes not found. Ensure google-gemini-php/client is installed and autoloaded.');
        } catch (\Throwable $e) {
            Yii::error('Failed creating Gemini client: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Failed to create Gemini client: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Call a method on the underlying Gemini client with error handling.
     * 
     * Wraps client method calls with Yii logging and exception handling.
     * 
     * @param string $method The client method name to call
     * @param array $args Method arguments
     * @return mixed The method return value
     * @throws \RuntimeException if method doesn't exist or execution fails
     */
    public function call(string $method, array $args = []): mixed
    {
        $client = $this->getClient();
        if (!method_exists($client, $method)) {
            throw new \RuntimeException("Gemini client method {$method} does not exist");
        }
        try {
            return call_user_func_array([$client, $method], $args);
        } catch (\Throwable $e) {
            $vendorExceptions = [
                '\\Gemini\\Exceptions\\TransporterException',
                '\\Gemini\\Exceptions\\ErrorException',
                '\\Gemini\\Exceptions\\UnserializableResponse',
            ];
            foreach ($vendorExceptions as $exClass) {
                if (class_exists($exClass) && $e instanceof $exClass) {
                    Yii::error('Gemini API error: ' . $e->getMessage(), __METHOD__);
                    throw $e;
                }
            }
            Yii::error('Gemini unexpected error: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Gemini unexpected error: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Magic method to proxy calls to the underlying Gemini client.
     * 
     * Allows calling any client method directly on the component instance.
     * 
     * @param string $name Method name
     * @param array $params Method parameters
     * @return mixed Method return value
     */
    public function __call($name, $params)
    {
        return $this->call($name, $params);
    }

    /**
     * Get a generative model instance by name.
     * 
     * Convenience method for accessing Gemini models.
     * 
     * @param string $name Model name (e.g., 'gemini-2.0-flash-exp', 'gemini-1.5-pro')
     * @return mixed The model instance
     * 
     * @example
     * ```php
     * $model = $gemini->generativeModel('gemini-2.0-flash-exp');
     * ```
     */
    public function generativeModel(string $name): mixed
    {
        return $this->call('generativeModel', [$name]);
    }

    //================================================================================
    // Cache & Conversation History Management
    //================================================================================

    /**
     * Get the configured Yii cache component instance.
     * 
     * @return \yii\caching\CacheInterface|null Cache instance or null if unavailable
     */
    protected function getCacheInstance(): ?\yii\caching\CacheInterface
    {
        if (empty($this->cacheComponent)) return null;
        try { return Yii::$app->get($this->cacheComponent); } catch (\Throwable $e) { Yii::warning('Gemini cache not available: '.$e->getMessage(), __METHOD__); return null; }
    }

    /**
     * Build a sanitized cache key for a conversation ID.
     * 
     * Replaces non-alphanumeric characters with underscores and adds prefix.
     * 
     * @param string $conversationId The conversation identifier
     * @return string The sanitized cache key
     */
    protected function buildCacheKey(string $conversationId): string
    {
        return $this->cachePrefix . preg_replace('/[^a-z0-9_\-]/i', '_', $conversationId);
    }

    /**
     * Load conversation history from Yii cache.
     * 
     * @param string $conversationId Unique conversation identifier
     * @return array|null Conversation history array or null if not found/unavailable
     * 
     * @example
     * ```php
     * $history = $gemini->loadConversation('user_123_session');
     * // Returns: [['role' => 'user', 'parts' => ['Hello']], ...]
     * ```
     */
    public function loadConversation(string $conversationId): ?array
    {
        // Use Yii cache component exclusively for conversation storage.
        $cache = $this->getCacheInstance();
        if ($cache === null) {
            Yii::warning('No cache component available for Gemini conversation storage.', __METHOD__);
            return null;
        }

        $key = $this->buildCacheKey($conversationId);
        $value = $cache->get($key);
        return is_array($value) ? $value : null;
    }

    /**
     * Save conversation history to Yii cache.
     * 
     * @param string $conversationId Unique conversation identifier
     * @param array $history Conversation history array
     * @return bool True on success, false if cache unavailable
     * 
     * @example
     * ```php
     * $history = [
     *     ['role' => 'user', 'parts' => ['What is AI?']],
     *     ['role' => 'model', 'parts' => ['AI is...']]
     * ];
     * $gemini->saveConversation('conv_123', $history);
     * ```
     */
    public function saveConversation(string $conversationId, array $history): bool
    {
        // Use Yii cache component exclusively for conversation storage.
        $cache = $this->getCacheInstance();
        if ($cache === null) {
            Yii::warning('No cache component available for Gemini conversation storage. Cannot save conversation.', __METHOD__);
            return false;
        }

        return $cache->set($this->buildCacheKey($conversationId), $history, $this->cacheTtl);
    }

    /**
     * Append a message to conversation history.
     * 
     * Helper method for building conversation history arrays.
     * 
     * @param array $history Current conversation history
     * @param string $role Message role ('user' or 'model')
     * @param string $text Message content
     * @return array Updated history with new message appended
     */
    public function appendToHistory(array $history, string $role, string $text): array
    {
        $history[] = ['role' => $role, 'parts' => [$text]]; return $history;
    }

    /**
     * Send a chat message to Gemini AI with optional conversation history.
     * 
     * This is the primary method for interacting with Gemini. It handles:
     * - Loading existing conversation history (if conversationId provided)
     * - Formatting history for context inclusion
     * - Sending the message to Gemini
     * - Parsing the response
     * - Saving updated history back to cache
     * 
     * @param string $modelId The Gemini model to use (e.g., 'gemini-2.0-flash-exp')
     * @param string $text The user's message text
     * @param string|null $conversationId Optional conversation ID for history tracking
     * @return array Response array with keys:
     *               - success: bool - Whether the request succeeded
     *               - response: string|null - The model's text response
     *               - history: array - Updated conversation history
     *               - error: string|null - Error message if failed
     * 
     * @example Basic chat without history:
     * ```php
     * $result = $gemini->chat('gemini-2.0-flash-exp', 'What is PHP?');
     * if ($result['success']) {
     *     echo $result['response'];
     * }
     * ```
     * 
     * @example Chat with conversation history:
     * ```php
     * // First message
     * $result1 = $gemini->chat('gemini-2.0-flash-exp', 'My name is John', 'conv_123');
     * 
     * // Follow-up message (will include previous context)
     * $result2 = $gemini->chat('gemini-2.0-flash-exp', 'What is my name?', 'conv_123');
     * // Response will be: "Your name is John"
     * ```
     */
    public function chat(string $modelId, string $text, ?string $conversationId = null): array
    {
        $convId = $conversationId ?? $this->conversationId; $localHistory = $this->history ?? [];
        if ($convId !== null) { $loaded = $this->loadConversation($convId); if (is_array($loaded)) $localHistory = array_merge($loaded, $localHistory); }

        try { $gen = $this->generativeModel($modelId); $session = $gen->startChat(); } catch (\Throwable $e) {
            return ['success' => false, 'response' => null, 'history' => $localHistory, 'error' => 'Failed to initialize chat: ' . $e->getMessage()];
        }

        $finalText = $text;
        if (!empty($localHistory)) {
            $parts = [];
            foreach ($localHistory as $m) {
                $role = $m['role'] ?? self::ROLE_USER;
                $content = is_array($m['parts']) ? implode("\n", $m['parts']) : (string)($m['parts'] ?? '');
                $parts[] = strtoupper($role) . ": " . $content;
            }
            $finalText = implode("\n", $parts) . "\n\n" . $text;
        }

        $response = null; $sendErrors = [];
        foreach (['sendMessage','send','message'] as $method) {
            if (!method_exists($session, $method)) continue;
            try { $response = $session->{$method}($finalText); break; } catch (\Throwable $e) { $sendErrors[] = $e->getMessage(); }
        }

        if ($response === null) return ['success' => false, 'response' => null, 'history' => $localHistory, 'error' => 'Failed to send message: ' . implode(' | ', $sendErrors)];

        $modelText = null;
        try {
            if (method_exists($response, 'text')) { $modelText = $response->text(); }
            elseif (method_exists($response, 'toArray')) { $arr = $response->toArray(); $modelText = is_string($arr) ? $arr : json_encode($arr); }
            else { $modelText = (string)$response; }
        } catch (\Throwable $e) { $modelText = null; }

        $this->history = $localHistory;
        if ($convId !== null && $modelText !== null) { $this->history = $this->appendToHistory($this->history, self::ROLE_USER, $text); $this->history = $this->appendToHistory($this->history, self::ROLE_MODEL, $modelText); $this->saveConversation($convId, $this->history); }

        return ['success' => true, 'response' => $modelText ?? ($response instanceof \Stringable ? (string)$response : null), 'history' => $this->history, 'error' => null];
    }

    /**
     * Build a GenerationConfig object from default and override options.
     * 
     * Merges defaultGenerationOptions with per-request overrides and creates
     * a Gemini\Data\GenerationConfig instance if the class is available.
     * 
     * @param array $overrides Options to override defaults
     * @return \Gemini\Data\GenerationConfig|null Config object or null if class unavailable
     * 
     * @example
     * ```php
     * $config = $gemini->buildGenerationConfig([
     *     'temperature' => 1.0,  // Override default
     *     'maxOutputTokens' => 4096,
     * ]);
     * ```
     */
    public function buildGenerationConfig(array $overrides = []): ?\Gemini\Data\GenerationConfig
    {
        if (!class_exists('\\Gemini\\Data\\GenerationConfig')) return null;
        $opts = array_merge($this->defaultGenerationOptions, $overrides);
        $meaningful = array_filter([$opts['candidateCount'] ?? null, $opts['stopSequences'] ?? null, $opts['maxOutputTokens'] ?? null, $opts['temperature'] ?? null, $opts['topP'] ?? null, $opts['topK'] ?? null], fn($v) => $v !== null && $v !== []);
        if (empty($meaningful)) return null;
        return new \Gemini\Data\GenerationConfig($opts['candidateCount'] ?? 1, $opts['stopSequences'] ?? [], $opts['maxOutputTokens'] ?? null, $opts['temperature'] ?? null, $opts['topP'] ?? null, $opts['topK'] ?? null);
    }

    /**
     * Apply generation options to a generative model instance.
     * 
     * Helper method to configure a model with generation parameters.
     * 
     * @param mixed $generativeModel The model instance to configure
     * @param array $overrides Options to override defaults
     * @return mixed The configured model instance (or original if config fails)
     */
    public function applyGenerationOptionsToModel($generativeModel, array $overrides = []): mixed
    {
        try { $gc = $this->buildGenerationConfig($overrides); if ($gc !== null && method_exists($generativeModel, 'withGenerationConfig')) return $generativeModel->withGenerationConfig($gc); } catch (\Throwable $e) { Yii::warning('Failed applying generation options: ' . $e->getMessage(), __METHOD__); }
        return $generativeModel;
    }
}