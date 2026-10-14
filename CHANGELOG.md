# Changelog

## 1.0.0 - 09-10-2026

- First release.
- `sms()`: `send`, `balance`, `delivery` (classic SMS API).
- `messages()`: `send` (automatic Idempotency-Key), `get`, `list` (CPaaS unified API with fallback).
- `verifyLink()`: `create`, `verify`, `get`, `cancel` (OneTap Verify).
- `emailVerify()`: `check`, `batch`, `batchStatus` (Email Verification).
- `Client::parseRedirect()` / `Client::getVerifyToken()` helpers.
- Retries on network errors, HTTP 429 (Retry-After honoured, max 10 s) and 5xx; `sms()->send()` only on connect failure and 429.
- ext-curl transport with PHP stream fallback; Composer and no-Composer (`autoload.php`) installs.
