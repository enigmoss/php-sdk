<?php

declare(strict_types=1);

namespace Enigmoss\Exception;

/**
 * The request conflicts with the current state (HTTP 409): order_id already used, object in a
 * state that does not allow the action, or a lock held by a parallel request.
 */
class ConflictException extends ApiException
{
    /**
     * ID of the object that already uses this order_id (details.id), if the server returned it.
     */
    public function existingResourceId(): ?string
    {
        return $this->detail('id');
    }

    /**
     * True when a create failed because the order_id already belongs to an object
     * (existingResourceId() is that object). False for a busy lock, a request still in
     * progress (IdempotencyException) or an object whose state does not allow the action.
     */
    public function isOrderIdTaken(): bool
    {
        return $this->errorCode === ErrorCode::RESOURCE_CONFLICT
            && $this->detail('id') !== null
            && !\array_key_exists('status', $this->details)
            && !\array_key_exists('reason', $this->details);
    }
}
