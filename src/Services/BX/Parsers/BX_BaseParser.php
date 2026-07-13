<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Parsers;

use BackedEnum;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Casters\DeletedAtFieldCaster;
use Pushkin42\LaravelHelpers\CommerceML\DTO\BaseParserDTO;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_FileParserInterface;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_IncomingFileDataInterface;
use Pushkin42\LaravelHelpers\CommerceML\Traits\ParserSharedTrait;
use SimpleXMLElement;

abstract class BX_BaseParser implements BX_FileParserInterface
{
    use ParserSharedTrait;

    protected ?string $rootKey = null;
    protected ?string $dtoClass = null;

    protected ?string $subKey = null;

    protected ?string $takeKey = null;

    protected ?string $recursiveKey = null;
    protected ?string $recursiveSubKey = null;

    protected ?array $shouldContainKeys = [];

    protected array $keyMap = [
        'Ид' => 'xml_id',
        'НомерВерсии' => 'version',
        'ПометкаУдаления' => 'deleted_at',
    ];

    protected array $casts = [
        'deleted_at' => DeletedAtFieldCaster::class,
    ];

    public function __construct(
        protected readonly BX_IncomingFileDataInterface $fileData
    )
    {
    }

    protected function beforeParse($input): mixed
    {
        return $input;
    }

    protected function afterParse($input): mixed
    {
        return $input;
    }

    protected function cleanupValue($value): mixed
    {
        if ((is_string($value) && trim($value) === '') || $value === []) return null;

        if ($value === 'true') return true;
        if ($value === 'false') return false;

        return match (gettype($value)) {
            'integer' => (int)$value,
            'boolean' => (bool)$value,
            'array' => (array)$value,
            'object' => (object)$value,
            default => trim((string)$value),
        };
    }

    protected function simpleXmlToArray(array|SimpleXMLElement $xml): array
    {
        if (is_array($xml)) {
            return $xml;
        }
        $data = json_decode(
            json_encode($xml, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($xml->attributes() as $key => $value) {
            $data['@attributes'][$key] = (string)$value;
        }

        return $data;
    }

    public function normalize(SimpleXMLElement|array $input, ?string $parentXmlId = null): array
    {

        $data = $this->simpleXmlToArray($input);

        $casts = $this->getKeymapFor('casts');
        $keyMap = $this->getKeymapFor('keyMap');

        return Arr::mapWithKeys($data, function ($v, $k) use ($data, $casts, $keyMap) {
            $v = $this->cleanupValue($v);
            $kk = data_get($keyMap, $k, $k);
            $vFunc = data_get($casts, $k, data_get($casts, $kk));

            if (is_array($vFunc)) {
                foreach ($vFunc as $f) {
                    $v = $this->vFunc($f, $v, $data);
                }
            } else {
                $v = $this->vFunc($vFunc, $v, $data);
            }

            if ($this->recursiveKey && $kk === $this->recursiveKey) {
                $nv = $this->recursiveSubKey ? data_get($v, $this->recursiveSubKey) : $v;
                $rv = [];
                if ($nv) {
                    $parentXmlId = data_get($data, 'Ид');
                    if (is_array($nv) && array_is_list($nv)) {
                        foreach ($nv as $sub) {
                            if ($parentXmlId) {
                                $sub['parent_xml_id'] = $parentXmlId;
                            }
                            $data = $this->normalize($sub, $parentXmlId);
                            if ($this->dtoClass) {
                                $rv[] = $this->dtoClass::makeFromRaw($data);
                            } else {
                                $rv[] = $data;
                            }
                        }
                    }
                    return [$kk => $rv];
                }
            }
            return [$kk => $v];
        });
    }

    public function supports(): bool
    {
        if ($this->rootKey === null) {
            throw new InvalidArgumentException("Не указан ключ для поиска");
        }
        $main = $this->fileData->hasXmlKey($this->rootKey);
        $sub = !($this->subKey) || $this->fileData->hasXmlKey($this->subKey);
        if (!empty($this->shouldContainKeys)) {
            $contain = $this->fileData->hasXmlKey($this->shouldContainKeys);
        } else $contain = true;
        return $main && $sub && $contain;
    }

    protected function getData(): LazyCollection
    {
        $targetKey = mb_ucfirst(trim($this->takeKey ?? $this->subKey ?? $this->rootKey));
        if (!$targetKey) return new LazyCollection();

        return LazyCollection::make(fn() => yield from $this->fileData->structure()->getXmlKeyData($targetKey));
    }

    public function parse(): LazyCollection
    {

        $data = $this->getData();

        return new LazyCollection(function () use ($data) {
            foreach ($data as $item) {
                $raw = $this->beforeParse($item);
                if ($raw === false || $raw === null) continue;

                $raw = $this->normalize($raw);

                $raw = $this->afterParse($raw);
                if ($raw === false || $raw === null) continue;

                if ($this->dtoClass && class_exists($this->dtoClass)) {
                    yield $this->dtoClass::factory()->from($raw);
                } else {
                    yield $raw;
                }
            };
        });
    }

}
