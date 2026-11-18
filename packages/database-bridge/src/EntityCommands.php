<?php

declare(strict_types=1);

namespace Shirakaba\DatabaseBridge;

use Closure;
use Shirakaba\Console\Argument;
use Shirakaba\Console\ConsoleBuilder;
use Shirakaba\Console\Option;
use Shirakaba\Console\Output;
use Shirakaba\Console\UsageError;
use Shirakaba\Database\Reader;
use Shirakaba\Orm\SchemaCheck;
use Shirakaba\Orm\Mapping\EntityMap;
use Shirakaba\Orm\Mapping\Field;
use Shirakaba\Orm\Mapping\Relation;

/**
 * Registers `entities`, `entities:show` and `entities:check`, so people and
 * coding agents can read how entities map to tables without reading attributes:
 *
 *     EntityCommands::register($cli, static fn (): array => [Task::class, Project::class], $reader(...));
 *     // app entities, app entities:show Task --json, app entities:check
 *
 * The listing commands take --json; the shape is documented in the guide's command-line
 * chapter.
 */
final readonly class EntityCommands
{
    /**
     * @param Closure(): list<class-string> $entities the application's entity classes
     * @param (Closure(): Reader)|null $reader the database entities:check compares with; the
     *                                         command is not registered without it
     */
    public static function register(ConsoleBuilder $cli, Closure $entities, ?Closure $reader = null, string $prefix = ''): void
    {
        $cli->command($prefix . 'entities', static function (Output $output, #[Option('Print JSON instead of a table')] bool $json) use ($entities): int {
            $maps = array_map(EntityMap::of(...), $entities());
            if ($json) {
                $output->line(self::encode(array_map(static fn (EntityMap $map): array => ['class' => $map->class, 'table' => $map->table], $maps)));

                return 0;
            }
            $width = max([0, ...array_map(static fn (EntityMap $map): int => strlen($map->class), $maps)]);
            foreach ($maps as $map) {
                $output->line(str_pad($map->class, $width) . '  ' . $map->table);
            }

            return 0;
        }, 'Lists the entity classes and their tables.');

        $cli->command($prefix . 'entities:show', static function (
            Output $output,
            #[Argument('An entity class, its short name or its table')] string $entity,
            #[Option('Print JSON instead of text')] bool $json,
        ) use ($entities): int {
            $map = self::find($entities(), $entity);
            if ($json) {
                $output->line(self::encode(self::details($map)));

                return 0;
            }
            foreach (self::text($map) as $line) {
                $output->line($line);
            }

            return 0;
        }, 'Shows one entity: its table, columns, relations and scopes.');

        if ($reader !== null) {
            $cli->command($prefix . 'entities:check', static function (Output $output) use ($entities, $reader): int {
                $problems = SchemaCheck::entities($entities(), $reader());
                foreach ($problems as $problem) {
                    $output->line($problem);
                }
                if ($problems === []) {
                    $output->line('The database matches every entity.');
                }

                return $problems === [] ? 0 : 1;
            }, 'Compares the entity mappings with the database and lists the differences.');
        }
    }

    /**
     * @param list<class-string> $classes
     * @return EntityMap<object>
     */
    private static function find(array $classes, string $entity): EntityMap
    {
        foreach ($classes as $class) {
            $map = EntityMap::of($class);
            $short = substr($class, (int) strrpos('\\' . $class, '\\'));
            if (in_array($entity, [$class, ltrim($class, '\\'), $short, $map->table], true)) {
                return $map;
            }
        }
        throw new UsageError(sprintf('No entity "%s"; run entities to list them.', $entity));
    }

    /**
     * @param EntityMap<object> $map
     * @return array<string, mixed>
     */
    private static function details(EntityMap $map): array
    {
        return [
            'class' => $map->class,
            'table' => $map->table,
            'columns' => array_map(self::column(...), self::flatten($map->fields)),
            'relations' => array_values(array_map(static fn (Relation $relation): array => array_filter([
                'name' => $relation->name,
                'kind' => $relation->kind,
                'entity' => $relation->entity,
                'foreignKey' => $relation->foreignKey,
                'through' => $relation->through,
                'localKey' => $relation->localKey,
                'orderBy' => $relation->orderBy,
            ], static fn (mixed $value): bool => $value !== null), $map->relations)),
            'defaultScopes' => $map->defaultScopes,
            'requiredScopes' => $map->requiredScopes,
            'softDelete' => $map->softDelete,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function column(Field $field): array
    {
        return [
            'property' => $field->property,
            'column' => $field->column,
            'type' => $field->type,
            'kind' => $field->kind,
            'nullable' => $field->nullable,
            'id' => $field->id,
            'generated' => $field->generated,
            'managed' => $field->version ? 'version' : ($field->createdAt ? 'createdAt' : ($field->updatedAt ? 'updatedAt' : null)),
            ...($field->hasDefault ? ['default' => $field->default instanceof \BackedEnum ? $field->default->value : (is_object($field->default) ? null : $field->default)] : []),
        ];
    }

    /**
     * Columns of embedded value objects are listed with the rest, under their property path.
     *
     * @param list<Field> $fields
     * @return list<Field>
     */
    private static function flatten(array $fields): array
    {
        $flat = [];
        foreach ($fields as $field) {
            $flat = [...$flat, ...($field->children === [] ? [$field] : self::flatten($field->children))];
        }

        return $flat;
    }

    /**
     * @param EntityMap<object> $map
     * @return list<string>
     */
    private static function text(EntityMap $map): array
    {
        $lines = [$map->class, 'table: ' . $map->table, 'columns:'];
        foreach (self::flatten($map->fields) as $field) {
            $notes = array_filter([
                $field->id ? 'id' : null,
                $field->generated ? 'generated' : null,
                $field->nullable ? 'nullable' : null,
                $field->version ? 'version' : null,
                $field->createdAt ? 'created at' : null,
                $field->updatedAt ? 'updated at' : null,
                $field->kind === 'json' ? 'json' : null,
            ]);
            $lines[] = sprintf('  %s => %s (%s%s)', $field->property, $field->column, $field->type, $notes === [] ? '' : ', ' . implode(', ', $notes));
        }
        $lines[] = 'relations:';
        foreach ($map->relations as $relation) {
            $lines[] = sprintf(
                '  %s: %s %s by %s%s',
                $relation->name,
                $relation->kind,
                $relation->entity,
                $relation->through === null ? $relation->foreignKey : sprintf('%s(%s, %s)', $relation->through, $relation->localKey ?? '', $relation->foreignKey),
                $relation->orderBy === null ? '' : ', ordered by ' . $relation->orderBy,
            );
        }
        if ($map->relations === []) {
            $lines[] = '  (none)';
        }
        if ($map->requiredScopes !== [] || $map->defaultScopes !== []) {
            $lines[] = 'scopes:';
            foreach ($map->requiredScopes as $scope) {
                $lines[] = '  required ' . $scope;
            }
            foreach ($map->defaultScopes as $scope) {
                $lines[] = '  default ' . $scope;
            }
        }
        if ($map->softDelete !== null) {
            $lines[] = 'soft delete: ' . $map->softDelete;
        }

        return $lines;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
