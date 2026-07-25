<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use RuntimeException;
use Throwable;

class MnbExcelException extends RuntimeException
{
    private string $errorCode;
    private string $category;
    /** @var array<string,mixed> */
    private array $context;
    private string $safeMessage;

    /**
     * Backward-compatible constructor.
     *
     * Existing usage such as new MnbExcelException('message') and
     * new MnbExcelException('message', 0, $previous) still works.
     * New package usage may pass a string error code as the second argument.
     *
     * @param string|int $codeOrErrorCode
     * @param string|Throwable|null $categoryOrPrevious
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $message = '',
        string|int $codeOrErrorCode = ErrorCode::RUNTIME_ERROR,
        string|Throwable|null $categoryOrPrevious = null,
        array $context = [],
        ?Throwable $previous = null,
        ?string $safeMessage = null
    ) {
        $runtimeCode = 0;
        $errorCode = ErrorCode::RUNTIME_ERROR;
        $category = 'runtime';

        if (is_int($codeOrErrorCode)) {
            $runtimeCode = $codeOrErrorCode;
        } else {
            $errorCode = $codeOrErrorCode !== '' ? $codeOrErrorCode : ErrorCode::RUNTIME_ERROR;
        }

        if ($categoryOrPrevious instanceof Throwable) {
            $previous = $categoryOrPrevious;
        } elseif (is_string($categoryOrPrevious) && $categoryOrPrevious !== '') {
            $category = $categoryOrPrevious;
        } else {
            $category = ErrorCode::categoryFor($errorCode);
        }

        $this->errorCode = $errorCode;
        $this->category = $category;
        $this->context = $context;
        $this->safeMessage = $safeMessage ?? ErrorCode::safeMessageFor($errorCode);

        parent::__construct($message, $runtimeCode, $previous);
    }

    /** @param array<string,mixed> $context */
    public static function withCode(
        string $message,
        string $errorCode,
        array $context = [],
        ?Throwable $previous = null,
        ?string $safeMessage = null,
        ?string $category = null
    ): self {
        return new self($message, $errorCode, $category ?? ErrorCode::categoryFor($errorCode), $context, $previous, $safeMessage);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function category(): string
    {
        return $this->category;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    /** @return array<string,mixed> */
    public function toErrorArray(bool $debug = false): array
    {
        return ErrorReporter::report($this, $debug);
    }
}
