<?php

declare(strict_types=1);

namespace Shirakaba\Migrations;

/**
 * One change a migration makes, as recorded by Schema for linting.
 */
final readonly class Operation
{
    /**
     * @param 'createTable'|'dropTable'|'renameTable'|'dropColumn'|'renameColumn'|'addColumn'|'addRequiredColumn'|'dropIndex'|'addIndex'|'addUniqueIndex'|'addForeignKey'|'createExtension'|'sql' $kind
     */
    public function __construct(
        public string $kind,
        public string $table,
        public string $subject = '',
    ) {}
}
