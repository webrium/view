<?php

declare(strict_types=1);

namespace Webrium\View;

/**
 * Immutable result of compiling a template.
 *
 * The line map uses one-based line numbers and maps generated PHP lines to
 * their corresponding lines in the original view source.
 */
final class CompiledTemplate
{
    /**
     * @param array<int,int> $lineMap
     */
    public function __construct(
        private string $code,
        private array $lineMap
    ) {}

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return array<int,int>
     */
    public function getLineMap(): array
    {
        return $this->lineMap;
    }
}
