# Changelog

All notable changes to `itstarter/sms` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Package scaffold, service provider and `config/itssms.php`.
- API contract v1 in `contract/openapi.yaml`.
- `PhoneNumber` and `Segments` helpers.
- HTTP `Client` with retries, idempotency keys, batching, local validation and typed exceptions.
