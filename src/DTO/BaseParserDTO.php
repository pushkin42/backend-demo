<?php

namespace Pushkin42\LaravelHelpers\CommerceML\DTO;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Pushkin42\LaravelHelpers\CommerceML\Traits\ParserSharedTrait;
use Spatie\LaravelData\Data;

class BaseParserDTO extends Data
{
    use ParserSharedTrait;

    public readonly ?int $versionNumber;
    public ?string $deleted_at = null;
    public ?string $version = null;

    public ?string $xml_id = null;

    protected array $keyMap = [
        'Ид' => 'xml_id',
    ];

    protected array $casts = [];


    protected function convertVersion(): void
    {

        if (property_exists($this, 'version') && $this->version !== null) {
            $this->versionNumber = $this->decodeVersion($this->version);
        } else {
            $this->versionNumber = null;
        }
    }

    public function __construct()
    {
        $this->convertVersion();
    }

    public static function makeFromRaw(array $raw): static
    {
        $map = static::getKeymapFor('keyMap');
        $casts = static::getKeymapFor('casts');

        $raw = Arr::mapWithKeys($raw, function ($v, $k) use ($casts, $map) {
            $kk = data_get($map, $k, $k);

            $vv = (!is_array($v)) ? match ($v) {
                'true' => true,
                'false' => false,
                is_numeric($v) => floatval($v),
                default => (!empty($v) ? trim($v) : null),
            } : $v;
            $cast = data_get($casts, $k);
            if($cast) {

            }
            return [$kk => $vv];
        });

        return static::factory()->from($raw);
    }

    function decodeVersion(string $version): int
    {
        $bin = base64_decode($version, true);

        if ($bin === false || strlen($bin) !== 8) {
            throw new InvalidArgumentException('Invalid version');
        }

        return unpack('J', $bin)[1]; // unsigned 64-bit big-endian
    }
}
