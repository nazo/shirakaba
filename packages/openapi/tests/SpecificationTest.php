<?php

declare(strict_types=1);

namespace Shirakaba\OpenApi\Tests;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shirakaba\Core\AppBuilder;
use Shirakaba\OpenApi\Generator;

/**
 * Validates a generated document against the official OpenAPI 3.1 schema
 * (tests/Fixtures/oas-3.1-schema.json, https://spec.openapis.org/oas/3.1/schema/2022-10-07).
 */
#[CoversNothing]
final class SpecificationTest extends TestCase
{
    public function testGeneratedDocumentMatchesTheOpenApiSchema(): void
    {
        $app = require __DIR__ . '/Fixtures/app.php';
        $generator = require __DIR__ . '/Fixtures/openapi.php';
        self::assertInstanceOf(AppBuilder::class, $app);
        self::assertInstanceOf(Generator::class, $generator);

        $schema = file_get_contents(__DIR__ . '/Fixtures/oas-3.1-schema.json');
        self::assertIsString($schema);
        // opis/json-schema resolves the schema's "$dynamicRef": "#meta" to the document root
        // instead of the "$dynamicAnchor": "meta" in $defs/schema. Without an extending
        // dialect both mean the same thing, so use the static reference.
        $schema = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $schema);
        // The default Validator fills in schema defaults, which then trip unevaluatedProperties.
        $validator = new CompliantValidator();
        $validator->resolver()?->registerRaw($schema, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07');

        $document = json_decode($generator->generate($app->describe())->toJson(), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate($document, 'https://spec.openapis.org/oas/3.1/schema/2022-10-07');

        $error = $result->error();
        self::assertNull($error, $error === null ? '' : (string) json_encode(new ErrorFormatter()->format($error), JSON_PRETTY_PRINT));
    }
}
