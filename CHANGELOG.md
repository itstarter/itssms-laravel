# Changelog

All notable changes to `itstarter/sms` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Package scaffold, service provider and `config/itssms.php`.
- API contract v1 in `contract/openapi.yaml`.
- `PhoneNumber` and `Segments` helpers.
- HTTP `Client` with retries, idempotency keys, batching, local validation and typed exceptions.
- `SmsManager`, `Sms` facade and fluent `PendingSms` builder, with named `accounts` (one key and client each).
- Notification channel: `ItsSmsChannel` and `ItsSmsMessage` (`toItsSms()`, `routeNotificationForItsSms()`).
- Signed webhooks: `VerifyItsSmsSignature` middleware, opt-in route (`webhook.path`), `MessageSubmitted`, `MessageDelivered` and `MessageFailed` events carrying a `WebhookEvent`, and `Sms::verifyWebhook()`.
- `Sms::fake()` with `assertSent`, `assertSentTo`, `assertSentCount`, `assertNotSent` and `assertNothingSent`.
- Optional `idempotencyKey()` on `PendingSms` and `ItsSmsMessage` (`:{i}` suffix per batch); the notification channel derives one from the notification id. `ItsSmsException::$sentResults` carries earlier batches when a later one fails.
- Webhook middleware logs a warning when the secret is missing.
- `composer.json` homepage and support links.
- README, `.gitattributes`; runtime requirements now include `illuminate/notifications` and `illuminate/routing`.

### Changed
- Contract: new error codes `module_disabled` (403), `not_found` (404) and `method_not_allowed` (405), and `plan` / `plan_renews_at` in the balance may be null.

### Security
- `Client` and `SmsManager` hide the API key from `print_r`/`var_dump`/`var_export` and refuse serialisation.
- Redirects are never followed, so the `Authorization` header cannot reach a redirect target.

### Fixed
- `SmsManager` no longer caches clients, so runtime config changes (multi-tenant, Octane) take effect.
