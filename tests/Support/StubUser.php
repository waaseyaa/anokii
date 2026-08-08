<?php

declare(strict_types=1);

namespace Anokii\Tests\Support;

use Anokii\Support\Values;
use Waaseyaa\Entity\EntityInterface;

/**
 * Plain-array {@see EntityInterface} stub: values are readable/writable
 * without the framework's sealed field-read layout, so tests can assert what
 * role application wrote (the same pattern the framework's own
 * UserAssignRoleHandlerTest uses).
 */
final class StubUser implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = []) {}

    public function id(): int|string
    {
        $id = $this->values['uid'] ?? 1;

        return is_int($id) || is_string($id) ? $id : 1;
    }

    public function uuid(): string
    {
        return Values::str($this->values['uuid'] ?? null, 'stub-uuid');
    }

    public function label(): string
    {
        return Values::str($this->values['name'] ?? null, 'stub');
    }

    public function getEntityTypeId(): string
    {
        return 'user';
    }

    public function bundle(): string
    {
        return 'user';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->values[$name] = $value;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
