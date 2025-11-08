# Changelog

All notable changes to this library will be documented in this file.

The format is inspired by Keep a Changelog, and this project adheres to Semantic Versioning.

## [2.0.0] - 2025-11-08

### Added
- Complete, REST-only Yii2 component for Google Gemini (`yii\httpclient` based).
- Text and multimodal generation (`generateContent`) with system instructions and safety settings.
- Streaming generation via SSE (`streamGenerateContent`).
- Client-side conversation caching (`chat` with `cacheType = 'client'`).
- Server-side context cache helpers (`createServerCache`, `chatServer`).
- Embeddings API (`embedContent`, `batchEmbedContents`).
- Files API (`uploadFile`, `listFiles`, `getFile`, `deleteFile`).
- Models API (`listModels`, `getModel`).
- Token counting helper (`countTokens`).
- Convenience helpers: `extractText`, `getFinishReason`, `getUsageMetadata`.

### Changed
- Replaced any SDK usage with native `yii\httpclient` calls.
- Introduced a uniform response shape: `{ ok, status, data, error }` for all methods.
- Strengthened typing: `declare(strict_types=1)`, union parameter types, `final` class.

### Fixed
- Client cache logic: lazy-load Yii cache component and store conversation turns reliably.
- Safer error handling and consistent error propagation from HTTP responses.

### Notes
- Files API upload flow is simplified (direct PUT with base64). Adapt if Gemini alters the upload protocol.
- Server cache creation generally requires a very large system instruction (≈32k tokens or more).

[2.0.0]: https://github.com/ldkafka/yii2-google-gemini/releases/tag/v2.0.0
