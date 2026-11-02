# Versioning and backward compatibility

The SDK follows [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`.

- **Patch** releases fix bugs and never change the public API.
- **Minor** releases add features in a backward-compatible way.
- **Major** releases may break the public API and may drop support for a PHP, Laravel or Symfony version. Breaking changes are described in [UPGRADE.md](../UPGRADE.md).

## PHP, Laravel and Symfony support

A PHP version is dropped **only in a major release**, never in a minor or patch release. The supported matrix (PHP 8.1 to 8.5, Laravel 10 to 13, Symfony 6.4, 7.4 and 8.x) is in the [README](../README.md#requirements-and-supported-versions).

## What is public API

Covered by the BC promise:

- `Enigmoss\EnigmossClient`, `ClientConfig`, `Secret`, `AppInfo`, `RequestOptions` and their public members.
- The services (`PaymentService` and so on): method names, parameters and return types.
- **Request and query DTOs** (`CreatePaymentRequest`, `ListPayoutsQuery`, ...): their **constructor parameter names are part of the API**, because you are expected to use named arguments. Parameters are renamed or removed only in a major release; new optional parameters are added at the end, in a minor release.
- Response model properties (`Payment::$status`, ...), enums and exceptions, as read members.
- `TransportInterface`, `HttpRequest`, `HttpResponse`, `ObserverInterface`, `ClientEvent`, `ClockInterface`, `SleeperInterface`.
- `WebhookVerifier`, the `WebhookEvent` classes and `WebhookDeduplicatorInterface`.
- The testing helpers (`MockTransport`, `Fixtures`, `WebhookSimulator`), the Laravel and Symfony bridges and their configuration keys.

## What is not public API

- **Everything in the `Enigmoss\Internal` namespace**, and anything marked `@internal`.
- **Constructors of response models** (`Payment`, `Payout`, `Refund`, `Wallet`, ...) are `@internal`: the SDK builds them. Their parameters can change in a minor release. In your tests use `Enigmoss\Testing\Fixtures` and `MockTransport` instead of constructing them.
- `fromPayload()` and `fromArray()` factories, `toBody()` and `toQuery()` methods.
- Private and protected members. `final` classes may not be extended; do not implement `EnigmossException`, because methods may be added to it in a minor release.
- The exact text of exception messages and of validation messages.

## Enums can grow

Enums that mirror server values (`PaymentStatus`, `PayoutStatus`, `RefundStatus`, `TransactionType`, `ErrorType`, `DeliveryStatus`, ...) **may gain cases in minor releases**, and the server may send values an older SDK does not know. Unknown values arrive as the `Unknown` case. Therefore:

- **Always keep a default arm** in `match` and `switch` over these enums.
- Prefer `isFinal` and helpers such as `PaymentStatus::isPaid()` over comparing every status.
- `Unknown` cannot be used in a request or as a filter; the SDK throws `InvalidArgumentException`.
- `ErrorCode` constants are a convenience list: the server may add codes at any time, so compare and keep a default branch.

```php
$label = match ($payout->status) {
    PayoutStatus::Completed => 'Sent',
    PayoutStatus::Failed, PayoutStatus::Cancelled => 'Not sent',
    default => 'In progress',
};
```

## Deprecations

Features are deprecated in a minor release (documented in the changelog and marked `@deprecated` with the replacement) and removed in the next major release.

## Fields the SDK does not model yet

New API fields appear in the server before the SDK models them. Use `RequestOptions(extraBody: [...], extraQuery: [...])` to send them, and `EnigmossClient::request()` for whole endpoints. The raw `data()` of a webhook event contains every field of the payload.
