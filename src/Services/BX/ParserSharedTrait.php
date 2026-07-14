<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Traits;

use Pushkin42\LaravelHelpers\CommerceML\DTO\BaseParserDTO;
use function PHPUnit\Framework\isBool;

trait ParserSharedTrait
{
    public static function getKeymapFor($key): array
    {
        $currentClass = static::class;
        $mergedKeymap = [];

        $classes = array_reverse(array_merge([$currentClass], class_parents($currentClass)));

        foreach ($classes as $class) {
            $defaultProperties = get_class_vars($class);

            if (isset($defaultProperties[$key])) {
                $mergedKeymap = array_merge($mergedKeymap, $defaultProperties[$key]);
            }
        }

        return $mergedKeymap;
    }

    public function booleanOr($value, $default = null): mixed
    {
        if ($value === null) return $default;
        if ($value === '00000000-0000-0000-0000-000000000000' || $value === [] || empty($value)) return null;
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        return $value;
    }

    public function ensureThatArrayIsList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            if (array_is_list($value)) return $value;
        }
        return [$value];
    }

    public function vFunc($vFunc, mixed $v, mixed $data = null)
    {
        if ($vFunc && (is_string($vFunc) || class_exists($vFunc))) {
            $parentXmlId = data_get($data, 'Ид');

            if (is_string($vFunc) && in_array($vFunc, ['array', 'integer', 'float', 'int'])) {
                $v = match ($vFunc) {
                    'array' => (array)$v,
                    'integer', 'int' => intval($v),
                    'float' => floatval($v),
                    default => $v,
                };
            } else {
                if (function_exists($vFunc)) {
                    $v = $vFunc($v);
                } elseif (is_callable($vFunc)) {
                    $v = $vFunc($v, $data, $parentXmlId);
                } elseif (method_exists($vFunc, '__invoke')) {
                    $v = app($vFunc)($v, $data, $parentXmlId);
                } elseif ($vFunc instanceof BaseParserDTO || is_subclass_of($vFunc, BaseParserDTO::class)) {
                    $v = $vFunc::makeFromRaw($v);
                } elseif (enum_exists($vFunc)) {
                    $v = $vFunc::tryFrom($v);
                }
            }
        }
        return $v;
    }
}
