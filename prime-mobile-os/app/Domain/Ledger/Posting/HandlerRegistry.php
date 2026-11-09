<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Enums\DocumentType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * document type → handler. Modules add theirs in a service provider:
 *
 *   $this->app->make(HandlerRegistry::class)->register(DocumentType::TransferOut, TransferOutHandler::class);
 */
final class HandlerRegistry
{
    /** @var array<string, class-string<DocumentHandler>|DocumentHandler> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<DocumentHandler>|DocumentHandler $handler */
    public function register(DocumentType $type, string|DocumentHandler $handler): void
    {
        if ($type === DocumentType::Reversal) {
            throw new InvalidArgumentException('Reversal documents are produced by Reverser, not by a handler.');
        }
        $this->handlers[$type->value] = $handler;
    }

    public function has(DocumentType $type): bool
    {
        return isset($this->handlers[$type->value]);
    }

    public function for(DocumentType $type): DocumentHandler
    {
        $handler = $this->handlers[$type->value] ?? null;
        if ($handler === null) {
            return new NotImplementedHandler($type);
        }
        if (is_string($handler)) {
            $handler = $this->handlers[$type->value] = $this->container->make($handler);
        }

        return $handler;
    }

    /** @return list<DocumentType> */
    public function registeredTypes(): array
    {
        return array_map(fn (string $t) => DocumentType::from($t), array_keys($this->handlers));
    }
}
