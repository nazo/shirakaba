<?php

declare(strict_types=1);

namespace Shirakaba\Form\Internal;

use Shirakaba\Core\Extraction\ArgumentResolver;
use Shirakaba\Core\Extraction\Exchange;
use Shirakaba\Form\FormBody;

/**
 * Reads and maps a form body. A plain serialisable object, so build plans can be cached (design/04-routing-and-handlers.md).
 *
 * @internal
 */
final readonly class FormBodyResolver implements ArgumentResolver
{
    /**
     * @param class-string $class
     * @param list<FormField> $fields
     */
    public function __construct(
        private string $class,
        private array $fields,
        private int $maxBytes,
        private bool $allowUnknown,
        private int $maxFields = 1000,
    ) {}

    public function resolve(Exchange $exchange): mixed
    {
        [$values, $files] = FormBody::input($exchange->request, $this->maxBytes, $this->maxFields);

        return FormBody::map($this->class, $this->fields, $values, $files, $this->allowUnknown);
    }
}
