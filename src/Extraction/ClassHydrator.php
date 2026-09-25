<?php declare(strict_types=1);
namespace LaravelSmartOCR\Extraction;
use LaravelSmartOCR\Extraction\Attributes\ListOf;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class ClassHydrator
{
    public static function hydrate(string $class, array $data): object
    {
        $rc = new ReflectionClass($class);
        $constructor = $rc->getConstructor();
        if (!$constructor) return $rc->newInstance();
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $args[$param->getName()] = self::castParam($param, $data[$param->getName()] ?? null);
        }
        return $rc->newInstanceArgs($args);
    }

    private static function castParam(ReflectionParameter $param, mixed $value): mixed
    {
        if ($value === null) {
            return $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        }
        $listAttrs = $param->getAttributes(ListOf::class);
        if ($listAttrs) {
            $itemClass = $listAttrs[0]->newInstance()->class;
            return is_array($value) ? array_map(fn($i) => self::hydrate($itemClass, (array)$i), $value) : [];
        }
        $type = $param->getType();
        if (!($type instanceof ReflectionNamedType)) return $value;
        return match ($type->getName()) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'string' => (string)$value,
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }
}
