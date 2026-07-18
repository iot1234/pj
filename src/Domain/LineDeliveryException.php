<?php
declare(strict_types=1);

namespace Dormitory\Domain;

final class LineDeliveryException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
