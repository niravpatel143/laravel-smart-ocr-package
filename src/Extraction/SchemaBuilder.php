<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;
use LaravelSmartOCR\Extraction\Attributes\Field;
use LaravelSmartOCR\Extraction\Attributes\ListOf;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class SchemaBuilder
{
    public static function fromClass(string $class): array
    {
        $rc = new ReflectionClass($class);
        $properties = [];
        $required = [];
        $constructor = $rc->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $schema = self::paramSchema($param);
                $properties[$param->getName()] = $schema;
                $fieldAttr = self::attr($param, Field::class);
                if ($fieldAttr?->required) {
                    $required[] = $param->getName();
                }
            }
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    public static function fromArray(array $schema): array
    {
        $properties = [];
        foreach ($schema as $key => $type) {
            $properties[$key] = match ($type) {
                'int', 'integer' => ['type' => 'integer'],
                'float', 'number', 'double' => ['type' => 'number'],
                'bool', 'boolean' => ['type' => 'boolean'],
                'array' => ['type' => 'array', 'items' => ['type' => 'string']],
                default => ['type' => 'string'],
            };
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => []];
    }

    private static function paramSchema(ReflectionParameter $param): array
    {
        $type = $param->getType();
        $fieldAttr = self::attr($param, Field::class);
        $listAttr  = self::attr($param, ListOf::class);

        $jsonType = 'string';
        if ($type instanceof ReflectionNamedType) {
            $jsonType = match ($type->getName()) {
                'int', 'integer' => 'integer',
                'float', 'double' => 'number',
                'bool', 'boolean' => 'boolean',
                'array' => 'array',
                default => 'string',
            };
        }

        $schema = ['type' => $jsonType];
        if ($fieldAttr) {
            $schema['description'] = $fieldAttr->description;
            if ($fieldAttr->format) $schema['format'] = $fieldAttr->format;
            if ($fieldAttr->example) $schema['example'] = $fieldAttr->example;
        }
        if ($listAttr) {
            $schema['type'] = 'array';
            $schema['items'] = self::fromClass($listAttr->class);
        }
        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            $schema['nullable'] = true;
        }
        return $schema;
    }

    /** @template T @param class-string<T> $attrClass @return T|null */
    private static function attr(ReflectionParameter $param, string $attrClass): mixed
    {
        $attrs = $param->getAttributes($attrClass);
        return $attrs ? $attrs[0]->newInstance() : null;
    }
}
