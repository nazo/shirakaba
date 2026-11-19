<?php

declare(strict_types=1);

namespace Shirakaba\Orm;

use Shirakaba\Database\Error\DatabaseError;
use Shirakaba\Database\Reader;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Field;

/**
 * Compares entity mappings with the live schema, for a CI step after migrations:
 *
 *     $problems = SchemaCheck::entities([Task::class, User::class], $reader);
 *     // ['Task: table "tasks" has no column "due_on"', ...]
 *
 * It reports missing tables and columns, and non-nullable properties over nullable columns,
 * which would fail when a NULL is read. Column types are not compared.
 */
final readonly class SchemaCheck
{
    /**
     * @param list<class-string> $classes
     * @return list<string> problems; empty when the mappings match
     * @throws DatabaseError
     */
    public static function entities(array $classes, Reader $db): array
    {
        $problems = [];
        foreach ($classes as $class) {
            $map = EntityMap::of($class);
            $short = substr((string) strrchr('\\' . $class, '\\'), 1);
            $columns = self::columns($map->table, $db);
            if ($columns === []) {
                $problems[] = sprintf('%s: table "%s" does not exist.', $short, $map->table);
                continue;
            }
            foreach (self::fields($map->fields) as $field) {
                $nullable = $columns[$field->column] ?? null;
                if ($nullable === null) {
                    $problems[] = sprintf('%s: table "%s" has no column "%s" for $%s.', $short, $map->table, $field->column, $field->property);
                } elseif ($nullable && !$field->nullable && !$field->generated) {
                    $problems[] = sprintf('%s: column "%s" allows NULL, but $%s is not nullable.', $short, $field->column, $field->property);
                }
            }
        }

        return $problems;
    }

    /**
     * @param list<Field> $fields
     * @return list<Field> stored fields, with embedded ones flattened
     */
    private static function fields(array $fields): array
    {
        $flat = [];
        foreach ($fields as $field) {
            if ($field->kind === 'embedded') {
                foreach (self::fields($field->children) as $child) {
                    // A nullable embedded value makes all its columns nullable.
                    $flat[] = $field->nullable
                        ? new Field($child->property, $child->column, $child->kind, $child->type, true, $child->hasDefault, $child->default)
                        : $child;
                }
                continue;
            }
            $flat[] = $field;
        }

        return $flat;
    }

    /**
     * The live columns of a table; empty when it does not exist.
     *
     * @return array<string, bool> column => nullable
     * @internal
     */
    public static function columns(string $table, Reader $db): array
    {
        $rows = match ($db->dialect()->name()) {
            'sqlite' => array_map(
                static fn (array $row): array => [
                    'name' => $row['name'] ?? null,
                    'nullable' =>
                        (int) (is_numeric($row['notnull'] ?? null) ? $row['notnull'] : 0) === 0
                            && (int) (is_numeric($row['pk'] ?? null) ? $row['pk'] : 0) === 0,
                ],
                $db->fetchAll(sprintf('PRAGMA table_info(%s)', $db->dialect()->quoteIdentifier($table))),
            ),
            'mysql'
                => $db->fetchAll("SELECT column_name AS name, is_nullable = 'YES' AS nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?", [
                $table,
            ]),
            default => $db->fetchAll("SELECT column_name AS name, is_nullable = 'YES' AS nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?", [
                $table,
            ]),
        };
        $columns = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name)) {
                $nullable = $row['nullable'] ?? false;
                $columns[$name] = $nullable === true || $nullable === 1 || $nullable === '1' || $nullable === 't';
            }
        }

        return $columns;
    }
}
