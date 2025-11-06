<?php
/**
 * @link https://github.com/ldkafka/yii2-google-gemini
 * @copyright Copyright (c) 2025 Lucian Kafka
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 */

/**
 * ldkafka\gemini\Gemini
 * Lightweight, cleaned-up migration of the original application component into a vendor package.
 * License: BSD-3-Clause
 */

namespace ldkafka\gemini;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Gemini extends Component
{
    public const VERSION = '1.0.0';
    public const CACHE_PREFIX = 'gemini_chat_';
    public const DEFAULT_CACHE_TTL = 3600;
    public const ROLE_USER = 'user';
    public const ROLE_MODEL = 'model';

    public array $config = [];
    public ?string $cacheComponent = 'cache';
    public int $cacheTtl = self::DEFAULT_CACHE_TTL;
    public string $cachePrefix = self::CACHE_PREFIX;
    public ?string $conversationId = null;
    public array $history = [];
    public array $defaultGenerationOptions = [];
    // Conversation storage: uses Yii cache component exclusively

    private $_client = null;

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

    public function getClient()
    {
        if ($this->_client === null) {
            $this->_client = $this->createClient();
        }
        return $this->_client;
    }

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

    public function __call($name, $params)
    {
        return $this->call($name, $params);
    }

    public function generativeModel(string $name): mixed
    {
        return $this->call('generativeModel', [$name]);
    }

    // Cache / conversation helpers
    protected function getCacheInstance(): ?\yii\caching\CacheInterface
    {
        if (empty($this->cacheComponent)) return null;
        try { return Yii::$app->get($this->cacheComponent); } catch (\Throwable $e) { Yii::warning('Gemini cache not available: '.$e->getMessage(), __METHOD__); return null; }
    }

    protected function buildCacheKey(string $conversationId): string
    {
        return $this->cachePrefix . preg_replace('/[^a-z0-9_\-]/i', '_', $conversationId);
    }

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

    public function appendToHistory(array $history, string $role, string $text): array
    {
        $history[] = ['role' => $role, 'parts' => [$text]]; return $history;
    }

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

    public function buildGenerationConfig(array $overrides = []): ?\Gemini\Data\GenerationConfig
    {
        if (!class_exists('\\Gemini\\Data\\GenerationConfig')) return null;
        $opts = array_merge($this->defaultGenerationOptions, $overrides);
        $meaningful = array_filter([$opts['candidateCount'] ?? null, $opts['stopSequences'] ?? null, $opts['maxOutputTokens'] ?? null, $opts['temperature'] ?? null, $opts['topP'] ?? null, $opts['topK'] ?? null], fn($v) => $v !== null && $v !== []);
        if (empty($meaningful)) return null;
        return new \Gemini\Data\GenerationConfig($opts['candidateCount'] ?? 1, $opts['stopSequences'] ?? [], $opts['maxOutputTokens'] ?? null, $opts['temperature'] ?? null, $opts['topP'] ?? null, $opts['topK'] ?? null);
    }

    public function applyGenerationOptionsToModel($generativeModel, array $overrides = []): mixed
    {
        try { $gc = $this->buildGenerationConfig($overrides); if ($gc !== null && method_exists($generativeModel, 'withGenerationConfig')) return $generativeModel->withGenerationConfig($gc); } catch (\Throwable $e) { Yii::warning('Failed applying generation options: ' . $e->getMessage(), __METHOD__); }
        return $generativeModel;
    }
}