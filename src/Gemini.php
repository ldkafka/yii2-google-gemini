<?php
declare(strict_types=1);
/**
 * Yii2 Google Gemini Component
 * 
 * A comprehensive Yii2 component for Google's Gemini API using native Yii2 HTTP Client.
 * Supports text generation, multimodal inputs, streaming, embeddings, file uploads, and context caching.
 * 
 * @package ldkafka\gemini
 * @version 2.0.0
 * @author Lucian Kafka
 * @license BSD 3-Clause License
 * @link https://github.com/ldkafka/yii2-google-gemini
 */

namespace ldkafka\gemini;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\httpclient\Client as HttpClient;

/**
 * Gemini Component
 * 
 * Provides a complete interface to Google's Gemini API with support for:
 * - Text and multimodal content generation
 * - Streaming responses via Server-Sent Events
 * - Conversation history with client or server-side caching
 * - File uploads for large media
 * - Text embeddings for RAG workflows
 * - Model discovery and capabilities
 * 
 * @property-read HttpClient $http The HTTP client instance
 */
final class Gemini extends Component
{
	/** @var string Component version */
	public const VERSION = '2.0.0';
	
	/** @var string Default Gemini API base URL */
	public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1/';

	/**
	 * @var string Google Gemini API key (required)
	 * Get your key at https://aistudio.google.com/apikey
	 */
	public string $apiKey = '';
	
	/**
	 * @var string API base URL
	 */
	public string $baseUrl = self::DEFAULT_BASE_URL;
	
	/**
	 * @var array Default generation configuration
	 * Options: temperature, topP, topK, maxOutputTokens, stopSequences, candidateCount
	 */
	public array $generationConfig = [];
	
	/**
	 * @var array Content safety settings
	 * Array of safety settings for different harm categories
	 */
	public array $safetySettings = [];
	
	/**
	 * @var array|null System instruction for all requests
	 * Format: ['parts' => [['text' => 'Your instruction']]]
	 */
	public ?array $systemInstruction = null;
	
	/**
	 * @var string Cache type: 'none' (stateless), 'client' (local history), 'server' (Gemini context cache)
	 */
	public string $cacheType = 'none';
	
	/**
	 * @var int Cache time-to-live in seconds
	 */
	public int $cacheTtl = 3600;
	
	/**
	 * @var string|null Yii cache component name (e.g., 'cache', 'redis')
	 */
	public ?string $cacheComponent = 'cache';
	
	/**
	 * @var array Custom HTTP client configuration
	 */
	public array $httpConfig = [];
	
	/**
	 * @var HttpClient|null HTTP client instance (lazy-loaded)
	 */
	private ?HttpClient $_httpClient = null;

	/**
	 * Constructor
	 * 
	 * @param array $config Component configuration
	 * @throws InvalidConfigException if apiKey is missing or cacheType is invalid
	 */
	public function __construct(array $config = [])
	{
		parent::__construct($config);
		if ($this->apiKey === '') {
			throw new InvalidConfigException('apiKey is required');
		}
		if (!in_array($this->cacheType, ['none', 'client', 'server'], true)) {
			throw new InvalidConfigException('cacheType must be: none, client, or server');
		}
	}

	/**
	 * Get or initialize the HTTP client
	 * 
	 * @return HttpClient Configured HTTP client instance
	 */
	protected function http(): HttpClient
	{
		if ($this->_httpClient === null) {
			$defaultCfg = [
				'baseUrl' => $this->baseUrl,
				'requestConfig' => ['format' => HttpClient::FORMAT_JSON],
				'responseConfig' => ['format' => HttpClient::FORMAT_JSON],
			];
			$this->_httpClient = new HttpClient(array_merge($defaultCfg, $this->httpConfig));
		}
		return $this->_httpClient;
	}

	/**
	 * Make an HTTP request to the Gemini API
	 * 
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 * @param string $endpoint API endpoint path
	 * @param array $data Request body data
	 * @return array Response with keys: ok, status, data, error
	 */
    protected function req(string $method, string $endpoint, array $data = []): array
	{
		try {
			$r = $this->http()->createRequest()
				->setMethod($method)
				->setUrl($endpoint)
				->addHeaders(['x-goog-api-key' => $this->apiKey]);
			if ($data) {
				$r->setData($data);
			}
			$res = $r->send();
			$dataOut = is_array($res->data) ? $res->data : (array)$res->data;
			return [
				'ok' => $res->isOk,
				'status' => $res->statusCode,
				'data' => $dataOut,
				'error' => $res->isOk ? null : ($dataOut['error']['message'] ?? 'error'),
			];
		} catch (\Throwable $e) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Generate content using the Gemini model
	 * 
	 * Supports text-only prompts, multimodal content (text + images/video/audio),
	 * and conversation history.
	 * 
	 * @param string $model Model name (e.g., 'gemini-2.5-flash')
	 * @param string|array $content Text string or array of content parts
	 * @param array $options Additional generation config to merge with defaults
	 * @return array Response with keys: ok, status, data, error
	 * 
	 * @example
	 * // Simple text
	 * $resp = $gemini->generateContent('gemini-2.5-flash', 'Hello');
	 * 
	 * // Multimodal (text + image)
	 * $content = [[
	 *     'parts' => [
	 *         ['text' => 'What is in this image?'],
	 *         ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($imageData)]]
	 *     ]
	 * ]];
	 * $resp = $gemini->generateContent('gemini-2.5-flash', $content);
	 */
	public function generateContent(string $model, string|array $content, array $options = []): array
	{
		$body = ['contents' => $this->buildContents($content)];
		$cfg = array_merge($this->generationConfig, $options);
		if ($cfg) {
			$body['generationConfig'] = $cfg;
		}
		if ($this->safetySettings) {
			$body['safetySettings'] = $this->safetySettings;
		}
		if ($this->systemInstruction) {
			$body['systemInstruction'] = $this->systemInstruction;
		}
		return $this->req('POST', 'models/' . $model . ':generateContent', $body);
	}

	/**
	 * Build contents array from various input formats
	 * 
	 * @param string|array $content Input content (string, single part object, or array of parts)
	 * @return array Normalized contents array for API
	 */
	protected function buildContents(string|array $content): array
	{
		// Simple text string
		if (is_string($content)) {
			return [['parts' => [['text' => $content]], 'role' => 'user']];
		}
		// Already formatted as array of content objects
		if (is_array($content) && isset($content[0])) {
			return $content;
		}
		// Single content object with parts
		if (is_array($content) && isset($content['parts'])) {
			return [$content];
		}
		// Fallback: convert to string
		return [['parts' => [['text' => (string)$content]], 'role' => 'user']];
	}

	/**
	 * Stream generated content with Server-Sent Events
	 * 
	 * Calls the callback function for each chunk of the response as it's generated.
	 * 
	 * @param string $model Model name
	 * @param string|array $content Text string or content array
	 * @param callable $callback Function to call with each chunk: function(array $chunk): void
	 * @param array $options Additional generation config
	 * @return array Response with keys: ok, status, error
	 * 
	 * @example
	 * $gemini->streamGenerateContent('gemini-2.5-flash', 'Write a story', function($chunk) {
	 *     if ($text = $chunk['candidates'][0]['content']['parts'][0]['text'] ?? null) {
	 *         echo $text;
	 *         flush();
	 *     }
	 * });
	 */
	public function streamGenerateContent(string $model, string|array $content, callable $callback, array $options = []): array
	{
		$body = ['contents' => $this->buildContents($content)];
		$cfg = array_merge($this->generationConfig, $options);
		if ($cfg) {
			$body['generationConfig'] = $cfg;
		}
		if ($this->safetySettings) {
			$body['safetySettings'] = $this->safetySettings;
		}
		if ($this->systemInstruction) {
			$body['systemInstruction'] = $this->systemInstruction;
		}
		
		try {
			$r = $this->http()->createRequest()
				->setMethod('POST')
				->setUrl('models/' . $model . ':streamGenerateContent?alt=sse')
				->addHeaders(['x-goog-api-key' => $this->apiKey])
				->setData($body);
			
			$buffer = '';
			$r->on(\yii\httpclient\Request::EVENT_AFTER_SEND, function($event) use ($callback, &$buffer) {
				$lines = explode("\n", $event->response->content);
				foreach ($lines as $line) {
					if (strpos($line, 'data: ') === 0) {
						$json = substr($line, 6);
						if ($data = json_decode($json, true)) {
							$callback($data);
						}
					}
				}
			});
			
			$res = $r->send();
			return ['ok' => $res->isOk, 'status' => $res->statusCode];
		} catch (\Throwable $e) {
			return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Count tokens in the given text
	 * 
	 * Useful for estimating API costs before making generation requests.
	 * 
	 * @param string $model Model name
	 * @param string $text Text to count tokens for
	 * @return int|null Token count, or null on error
	 */
	public function countTokens(string $model, string $text): ?int
	{
		$resp = $this->req('POST', 'models/' . $model . ':countTokens', [
			'contents' => [['parts' => [['text' => $text]]]],
		]);
		return $resp['ok'] ? ($resp['data']['totalTokens'] ?? null) : null;
	}

	/**
	 * Chat with conversation history (client-side caching)
	 * 
	 * When cacheType is 'client', maintains conversation history in Yii cache.
	 * When cacheType is 'none', behaves like generateContent (stateless).
	 * 
	 * @param string $model Model name
	 * @param string $text User message
	 * @param string|null $id Conversation ID for history storage
	 * @param array $options Additional generation config
	 * @return array Response with keys: ok, status, data, error
	 * 
	 * @example
	 * $gemini->cacheType = 'client';
	 * $resp = $gemini->chat('gemini-2.5-flash', 'My name is Alice', 'user123');
	 * $resp = $gemini->chat('gemini-2.5-flash', 'What is my name?', 'user123');
	 * // Response: "Your name is Alice."
	 */
	public function chat(string $model, string $text, ?string $id = null, array $options = []): array
	{
		// If no caching, just generate content
		if ($this->cacheType === 'none') {
			return $this->generateContent($model, $text, $options);
		}
		
		// Load conversation history from cache
		$history = [];
		$cache = null;
		if ($this->cacheComponent) {
			try {
				$cache = Yii::$app->get($this->cacheComponent);
			} catch (\Throwable $e) {
				// Cache component not available or connection failed - continue without cache
				$cache = null;
			}
		}
		
		if ($id && $this->cacheType === 'client' && $cache) {
			try {
				$history = $cache->get('gem_chat_' . $id) ?: [];
			} catch (\Throwable $e) {
				// Cache read failed - continue with empty history
				$history = [];
			}
		}
		
		// Add user message to history
		$history[] = ['role' => 'user', 'parts' => [['text' => $text]]];
		
		// Build request with full history
		$body = ['contents' => $history];
		$cfg = array_merge($this->generationConfig, $options);
		if ($cfg) {
			$body['generationConfig'] = $cfg;
		}
		if ($this->safetySettings) { 
			$body['safetySettings'] = $this->safetySettings;
		}
		
		// Make request
		$resp = $this->req('POST', 'models/' . $model . ':generateContent', $body);
		
		// Save updated history with model response
		if ($resp['ok'] && $id && $this->cacheType === 'client' && $cache) {
			$reply = $resp['data']['candidates'][0]['content']['parts'][0]['text'] ?? null;
			if ($reply) {
				$history[] = ['role' => 'model', 'parts' => [['text' => $reply]]];
				try {
					$cache->set('gem_chat_' . $id, $history, $this->cacheTtl);
				} catch (\Throwable $e) {
					// Cache write failed - ignore and continue
				}
			}
		}
		
		return $resp;
	}

	/**
	 * Create a server-side cached content
	 * 
	 * Creates a CachedContent resource on Gemini's servers with a system instruction.
	 * Requires minimum 32,000 tokens in the cached content.
	 * 
	 * @param string $model Model name
	 * @param string $id Local cache ID for storing the cachedContent name
	 * @param string $system System instruction text (must be 32k+ tokens)
	 * @param int|null $ttl Cache TTL in seconds (defaults to $this->cacheTtl)
	 * @return string|null CachedContent name (e.g., 'cachedContents/abc123'), or null on error
	 * 
	 * @example
	 * $cacheName = $gemini->createServerCache(
	 *     'gemini-2.5-flash',
	 *     'travel-assistant',
	 *     '[Very long system instruction with 32k+ tokens...]',
	 *     3600
	 * );
	 */
	public function createServerCache(string $model, string $id, string $system, ?int $ttl = null): ?string
	{
		$ttl = $ttl ?? $this->cacheTtl;
		$body = [
			'model' => 'models/' . $model,
			'systemInstruction' => ['parts' => [['text' => $system]]],
			'ttl' => $ttl . 's',
		];
		$resp = $this->req('POST', 'cachedContents', $body);
		if (!$resp['ok']) {
			return null;
		}
		$name = $resp['data']['name'] ?? null;
		
		// Store the cache name locally for reuse
		if ($name && $this->cacheComponent) {
			try {
				$cache = Yii::$app->get($this->cacheComponent);
				if ($cache) {
					$cache->set('gem_srv_cache_' . $id, $name, $ttl);
				}
			} catch (\Throwable $e) {
				// Cache write failed - ignore and return the cache name anyway
			}
		}
		return $name;
	}

	/**
	 * Chat using server-side cached content
	 * 
	 * Uses a previously created CachedContent resource for the system instruction.
	 * Creates a new cache if one doesn't exist for the given ID.
	 * 
	 * @param string $model Model name
	 * @param string $text User message
	 * @param string $id Cache ID (used to lookup cachedContent name)
	 * @param array $options Additional generation config
	 * @return array Response with keys: ok, status, data, error
	 * 
	 * @example
	 * $gemini->cacheType = 'server';
	 * $resp = $gemini->chatServer('gemini-2.5-flash', 'Best beaches in Sydney?', 'travel-assistant');
	 */
	public function chatServer(string $model, string $text, string $id, array $options = []): array
	{
		// Try to get cached content name from local cache
		$cache = null;
		$cacheName = null;
		
		if ($this->cacheComponent) {
			try {
				$cache = Yii::$app->get($this->cacheComponent);
				if ($cache) {
					$cacheName = $cache->get('gem_srv_cache_' . $id);
				}
			} catch (\Throwable $e) {
				// Cache not available - will create new server cache
				$cache = null;
				$cacheName = null;
			}
		}
		
		// Create cache if it doesn't exist
		if (!$cacheName) {
			$cacheName = $this->createServerCache($model, $id, 'You are a helpful AI assistant.');
		}
		if (!$cacheName) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Failed to create or retrieve cache'];
		}
		
		// Build request with cachedContent reference
		$body = [
			'contents' => [['parts' => [['text' => $text]], 'role' => 'user']],
			'cachedContent' => $cacheName,
		];
		$cfg = array_merge($this->generationConfig, $options);
		if ($cfg) {
			$body['generationConfig'] = $cfg;
		}
		if ($this->safetySettings) {
			$body['safetySettings'] = $this->safetySettings;
		}
		return $this->req('POST', 'models/' . $model . ':generateContent', $body);
	}
	
	/**
	 * List all available Gemini models
	 * 
	 * @return array Response with list of models and their capabilities
	 */
	public function listModels(): array
	{
		return $this->req('GET', 'models');
	}

	/**
	 * Get details about a specific model
	 * 
	 * @param string $model Model name (e.g., 'gemini-2.5-flash')
	 * @return array Response with model details (displayName, inputTokenLimit, etc.)
	 */
	public function getModel(string $model): array
	{
		return $this->req('GET', 'models/' . $model);
	}

	/**
	 * Upload a file to the Gemini Files API
	 * 
	 * For large media files (videos, documents) that exceed inline size limits.
	 * 
	 * @param string $filePath Local file path
	 * @param string|null $displayName Display name (defaults to filename)
	 * @param string|null $mimeType MIME type (auto-detected if null)
	 * @return array Response with file URI
	 * 
	 * @example
	 * $resp = $gemini->uploadFile('/path/to/video.mp4', 'My Video', 'video/mp4');
	 * $fileUri = $resp['data']['file']['uri'];
	 */
	public function uploadFile(string $filePath, ?string $displayName = null, ?string $mimeType = null): array
	{
		$mimeType = $mimeType ?? mime_content_type($filePath);
		$displayName = $displayName ?? basename($filePath);
		$fileData = base64_encode(file_get_contents($filePath));
		
		$body = [
			'file' => [
				'displayName' => $displayName,
				'mimeType' => $mimeType,
			],
		];
		
		$resp = $this->req('POST', 'files', $body);
		if (!$resp['ok']) {
			return $resp;
		}

		$uploadUrl = $resp['data']['file']['uri'] ?? null;
		if (!$uploadUrl) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'No upload URL returned'];
		}

		// Note: Actual Gemini Files API may require a different upload flow (multipart / resumable). This simplified
		// implementation assumes a direct PUT with base64 payload; adjust if API changes.
		return $this->req('PUT', $uploadUrl, ['data' => $fileData]);
	}

	/**
	 * Get metadata for an uploaded file
	 * 
	 * @param string $fileName File name (e.g., 'files/abc123')
	 * @return array Response with file metadata
	 */
	public function getFile(string $fileName): array
	{
		return $this->req('GET', 'files/' . $fileName);
	}

	/**
	 * List all uploaded files
	 * 
	 * @return array Response with array of file metadata
	 */
	public function listFiles(): array
	{
		return $this->req('GET', 'files');
	}

	/**
	 * Delete an uploaded file
	 * 
	 * @param string $fileName File name (e.g., 'files/abc123')
	 * @return array Response indicating success or failure
	 */
	public function deleteFile(string $fileName): array
	{
		return $this->req('DELETE', 'files/' . $fileName);
	}

	/**
	 * Generate an embedding vector for the given content
	 * 
	 * Used for semantic search, RAG (Retrieval-Augmented Generation), and similarity comparisons.
	 * 
	 * @param string $model Embedding model name (e.g., 'text-embedding-004')
	 * @param string|array $content Text or content object
	 * @param string|null $taskType Task type: 'RETRIEVAL_QUERY', 'RETRIEVAL_DOCUMENT', 'SEMANTIC_SIMILARITY', etc.
	 * @return array Response with embedding values
	 * 
	 * @example
	 * $resp = $gemini->embedContent('text-embedding-004', 'Hello world', 'RETRIEVAL_QUERY');
	 * $embedding = $resp['data']['embedding']['values']; // Array of floats
	 */
	public function embedContent(string $model, string|array $content, ?string $taskType = null): array
	{
		$body = ['content' => is_string($content) ? ['parts' => [['text' => $content]]] : $content];
		if ($taskType) {
			$body['taskType'] = $taskType;
		}
		return $this->req('POST', 'models/' . $model . ':embedContent', $body);
	}

	/**
	 * Generate embeddings for multiple contents in a single request
	 * 
	 * More efficient than calling embedContent multiple times.
	 * 
	 * @param string $model Embedding model name
	 * @param array $requests Array of request objects with 'content' and optional 'taskType'
	 * @return array Response with array of embeddings
	 * 
	 * @example
	 * $requests = [
	 *     ['content' => ['parts' => [['text' => 'Doc 1']]], 'taskType' => 'RETRIEVAL_DOCUMENT'],
	 *     ['content' => ['parts' => [['text' => 'Doc 2']]], 'taskType' => 'RETRIEVAL_DOCUMENT'],
	 * ];
	 * $resp = $gemini->batchEmbedContents('text-embedding-004', $requests);
	 */
	public function batchEmbedContents(string $model, array $requests): array
	{
		$body = ['requests' => $requests];
		return $this->req('POST', 'models/' . $model . ':batchEmbedContents', $body);
	}

	/**
	 * Helper: Extract text from a generation response
	 * 
	 * @param array $data Response data from generateContent
	 * @return string|null The generated text, or null if not found
	 */
	public function extractText(array $data): ?string
	{
		if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
			return $data['candidates'][0]['content']['parts'][0]['text'];
		}
		return null;
	}

	/**
	 * Helper: Get the finish reason from a generation response
	 * 
	 * @param array $data Response data from generateContent
	 * @return string|null Finish reason ('STOP', 'MAX_TOKENS', 'SAFETY', etc.), or null
	 */
	public function getFinishReason(array $data): ?string
	{
		return $data['candidates'][0]['finishReason'] ?? null;
	}

	/**
	 * Helper: Get usage metadata (token counts) from a response
	 * 
	 * @param array $data Response data from generateContent
	 * @return array|null Usage metadata with promptTokenCount, candidatesTokenCount, totalTokenCount
	 */
	public function getUsageMetadata(array $data): ?array
	{
		return $data['usageMetadata'] ?? null;
	}
}
