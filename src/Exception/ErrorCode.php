<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * Known error.code values. Codes are stable strings; the server may add new ones at any time,
 * so compare ApiException::$errorCode with these constants and keep a default branch.
 */
final class ErrorCode
{
    // Shared
    public const REQUEST_INVALID = 'request.invalid';
    public const RESOURCE_NOT_FOUND = 'resource.not_found';
    public const RESOURCE_CONFLICT = 'resource.conflict';
    public const LOCK_NOT_ACQUIRED = 'lock.not_acquired';
    public const DOMAIN_RULE_VIOLATION = 'domain.rule_violation';
    public const VALIDATION_FAILED = 'validation.failed';
    public const RATE_LIMIT_EXCEEDED = 'rate_limit.exceeded';
    public const INTERNAL_SERVER_ERROR = 'internal.server_error';
    /** An unexpected error on the API side; check the object with a GET before sending it again. */
    public const INTERNAL_UNEXPECTED = 'internal.unexpected';
    public const DEPENDENCY_GATEWAY = 'dependency.gateway';
    public const DEPENDENCY_EXTERNAL_SERVICE = 'dependency.external_service';
    /** The API or its gateway is temporarily unreachable (HTTP 502/503/504); retryable. */
    public const DEPENDENCY_UNAVAILABLE = 'dependency.unavailable';

    // Authentication and access
    public const SIGNATURE_INVALID = 'auth.signature_invalid';
    public const TIMESTAMP_EXPIRED = 'auth.timestamp_expired';
    public const PROJECT_UNKNOWN = 'auth.project_unknown';
    public const KEY_INACTIVE = 'auth.key_inactive';
    public const IP_FORBIDDEN = 'auth.ip_forbidden';
    /** The request came to an API domain that is not allowed for the project. */
    public const DOMAIN_FORBIDDEN = 'auth.domain_forbidden';
    public const PERMISSION_DENIED = 'api_permission.denied';

    // Idempotency
    public const IDEMPOTENCY_KEY_REQUIRED = 'idempotency.key_required';
    public const IDEMPOTENCY_KEY_INVALID = 'idempotency.key_invalid';
    public const IDEMPOTENCY_PAYLOAD_MISMATCH = 'idempotency.payload_mismatch';
    public const IDEMPOTENCY_IN_PROGRESS = 'idempotency.in_progress';

    // Payouts
    public const PAYOUT_PAYOUTS_FORBIDDEN = 'payout.payouts_forbidden';
    public const PAYOUT_INSUFFICIENT_FUNDS = 'payout.insufficient_funds';
    public const PAYOUT_NOT_CANCELLABLE = 'payout.not_cancellable';

    // Refunds
    public const REFUND_PAYOUTS_FORBIDDEN = 'refund.payouts_forbidden';
    public const REFUND_PAYMENT_NOT_REFUNDABLE = 'refund.payment_not_refundable';
    public const REFUND_WALLET_NOT_REFUNDABLE = 'refund.wallet_not_refundable';
    public const REFUND_AMOUNT_EXCEEDS_PAID = 'refund.amount_exceeds_paid';
    public const REFUND_AMOUNT_TOO_SMALL = 'refund.amount_too_small';
    public const REFUND_INSUFFICIENT_FUNDS = 'refund.insufficient_funds';
    public const REFUND_AML_NOT_SUPPORTED = 'refund.aml_not_supported';
    public const REFUND_NOTHING_TO_REFUND = 'refund.nothing_to_refund';

    // Transfers
    public const TRANSFER_WALLET_NOT_FOUND = 'transfer.wallet_not_found';
    public const TRANSFER_PAYOUTS_FORBIDDEN = 'transfer.payouts_forbidden';
    public const TRANSFER_PERSONAL_WALLET_NOT_FOUND = 'transfer.personal_wallet_not_found';
    public const TRANSFER_INSUFFICIENT_FUNDS = 'transfer.insufficient_funds';
    public const TRANSFER_AMOUNT_BELOW_MIN = 'transfer.amount_below_min';
    public const TRANSFER_AMOUNT_ABOVE_MAX = 'transfer.amount_above_max';

    // Webhook events API
    public const WEBHOOK_EVENT_NOT_LATEST = 'webhook.event_not_latest';
    public const WEBHOOK_REDELIVERY_TOO_MANY_EVENTS = 'webhook.redelivery_too_many_events';
    public const WEBHOOK_REDELIVERY_LIMIT_REACHED = 'webhook.redelivery_limit_reached';
    public const WEBHOOK_EVENT_NOT_REDELIVERABLE = 'webhook.event_not_redeliverable';

    private function __construct() {}
}
