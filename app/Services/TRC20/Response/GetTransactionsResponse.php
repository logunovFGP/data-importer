<?php

declare(strict_types=1);

namespace App\Services\TRC20\Response;

use App\Services\LunchFlow\Model\Transaction;
use App\Services\Shared\Response\Response;
use Countable;
use Illuminate\Support\Collection;
use Iterator;

class GetTransactionsResponse extends Response implements Iterator, Countable
{
    private readonly Collection $collection;
    private array $raw = [];
    private ?string $nextCursor = null;
    private int $position = 0;
    private bool $processed = false;

    public function __construct(private readonly array $data)
    {
        $this->collection = new Collection();
        $this->raw        = $data;
    }

    public function setNextCursor(?string $cursor): void
    {
        $cursor = trim((string)$cursor);
        $this->nextCursor = '' === $cursor ? null : $cursor;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function hasNextCursor(): bool
    {
        return null !== $this->nextCursor;
    }

    public function isUsdtOnly(): bool
    {
        foreach ($this->raw as $row) {
            if ('' === (string)($row['currency'] ?? $row['token_symbol'] ?? '')) {
                return false;
            }
            if ('USDT' !== strtoupper((string)($row['currency'] ?? $row['token_symbol']))) {
                return false;
            }
        }

        return true;
    }

    public function getTokenSymbols(): array
    {
        $symbols = [];
        foreach ($this->raw as $row) {
            $symbol = strtoupper((string)($row['token_symbol'] ?? $row['currency'] ?? ''));
            if ('' !== $symbol && !in_array($symbol, $symbols, true)) {
                $symbols[] = $symbol;
            }
        }

        return $symbols;
    }

    public function hasTokenSymbol(string $symbol): bool
    {
        $normalized = strtoupper(trim($symbol));
        foreach ($this->raw as $row) {
            $rowSymbol = strtoupper((string)($row['token_symbol'] ?? $row['currency'] ?? ''));
            if ($normalized === $rowSymbol) {
                return true;
            }
        }

        return false;
    }

    public function hasTokenContract(string $contract): bool
    {
        $normalized = strtolower(trim($contract));
        foreach ($this->raw as $row) {
            if ($normalized === strtolower((string)($row['token_contract'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public function getRawData(): array
    {
        return $this->raw;
    }

    public function processData(): void
    {
        if ($this->processed) {
            return;
        }
        foreach ($this->raw as $row) {
            $row['accountId'] = (string)($row['accountId'] ?? '');
            $this->collection->push(Transaction::fromArray($row));
        }
        $this->processed = true;
    }

    public function count(): int
    {
        return $this->collection->count();
    }

    public function current(): ?Transaction
    {
        return $this->collection->get($this->position);
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return $this->collection->has($this->position);
    }
}

