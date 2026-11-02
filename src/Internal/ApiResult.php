<?php

declare(strict_types=1);

namespace Enigmoss\Internal;

use Enigmoss\Http\ResponseInfo;

/**
 * @internal
 */
final class ApiResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $meta,
        public readonly string $body,
        public readonly ResponseInfo $info,
    ) {}

    public function payload(): Payload
    {
        return Payload::from($this->data);
    }

    /**
     * @return list<Payload>
     */
    public function payloads(): array
    {
        if (!\is_array($this->data) || !array_is_list($this->data)) {
            throw new \Enigmoss\Exception\UnexpectedResponseException('Expected "data" to be a list.', $this->info->statusCode, $this->info->requestId);
        }

        $items = [];
        foreach ($this->data as $index => $item) {
            $items[] = Payload::from($item, 'data.' . $index);
        }

        return $items;
    }
}
