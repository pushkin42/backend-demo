<?php

namespace App\Actions\LLM\Contexts;

use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Stringable;

class ProductContext extends BaseContextDTO
{

    public string $cleanName;
    public string $categoryName;

    public ?string $article = null;
    public ?string $manufacturerName = null;
    public ?string $actualCategory = null;

    public ?array $unparsed_tokens = [];

    public ?string $realManufacturerName = null;

    public array $props = [];

    protected array $keyMap = [
        'category_name' => 'categoryName',
        'NAME' => 'originalName',
        'name' => 'originalName',
        'manufacturer' => 'manufacturerName',
        'actual_category' => 'actualCategory',
    ];

    public string $originalName;

    public ?Stringable $tempName;

    public function ensureNamePrefix(string $prefix): self
    {
        if (!str($this->cleanName)->lower()->startsWith(str($prefix)->lower())) {
            $n = trim($this->cleanName);
            $this->cleanName = "$prefix $n";
        }

        return $this;
    }

    public function __construct()
    {
    }

    public function categoryContains(string|array $needle): bool
    {
        return
            str($this->categoryName)->lower()->contains($needle, true)
            ||
            str($this->actualCategory)->lower()->contains($needle, true);
    }

    public function replaceOriginal(string $needle, string $replace, bool $touchTemp = false): void
    {
        if (preg_match($needle, '') !== false) {
            $this->originalName = trim(preg_replace($needle, $replace, $this->originalName));
        } else {
            $this->originalName = trim(str_replace($needle, $replace, $this->originalName));
        }

        if ($touchTemp) {
            $this->tempName = str($this->originalName)->trim();
        }
    }

    function setDensity(float $density): self
    {
        $this->addPropInternal('density', $density);
        return $this;
    }

    public function overwriteProperty(string $key, mixed $value): void
    {
        data_forget($this->props, $key);
        data_set($this->props, $key, $value);
    }

    public function addCollection(string|array $c): static
    {
        if (is_array($c)) {
            foreach ($c as $cc) {
                $this->addCollection($cc);
            }
            return $this;
        }
        $cl = $this->getProperty('collection', default: []);

        return $this->setProperty('collection', array_merge($cl, [$c]));
    }

    public function addModifier(string $c): static
    {
        return $this->setAttribute('modifiers', array_merge($this->getProperty('modifiers', default: []), [$c]));
    }

    public function cleanupProps(): void
    {
        foreach ($this->props as $prop => $value) {
            if (empty($value)) unset($this->props[$prop]);
        }
    }


    private function mapKey(string $key): string
    {
        return $this->keyMap[$key] ?? $key;
    }

    public function __set(string $key, mixed $value)
    {
        $key = $this->mapKey($key);

        $value = str($value)->fixEncoding()->trim()->value();

        if (property_exists($this, $key)) {
            $this->$key = $value;
        } else {
            $this->data[$key] = $value;
        }
    }

    public function __get(string $key): mixed
    {
        $value = $this->$key ?? data_get($this->data, $key);
        return ($value) ? (is_string($value) ? trim($value) : $value) : null;
    }

    public static function make(mixed $data, array $keyMap = []): static
    {

        $s = new static();

        $s->keyMap = array_merge($s->keyMap, $keyMap);
        $data = Arr::mapWithKeys($data, fn($v, $k) => [mb_strtolower($k) => trim($v)]);

        foreach ($data as $key => $value) {
            $s->__set($key, $value);
        }

        $s->originalName = str_replace('WalI Decor', 'Wall Decor', $s->originalName);
        $s->tempName = str($s->originalName)->squish()->trim();

        return $s;
    }

    public static function makeMany(array $items, array $keyMap = []): LazyCollection
    {
        $ret = [];
        foreach ($items as $item) {
            $ret[] = self::make($item, $keyMap);
        }
        return new LazyCollection($ret);
    }

    public function setProperty(string $type, string|array|null $keyOrValue, mixed $value = null): static
    {
        if ($value === null && $keyOrValue === null) {
            // del prop
            data_forget($this->props, $type);
            return $this;
        }

        if ($value === null) {
            data_set($this->props, $type, $keyOrValue);
        } else {
            data_set($this->props, "$type.$keyOrValue", $value);
        }

        return $this;
    }

    public function setAttribute(string $name, mixed $value): static
    {
        $this->setProperty("attributes.$name", $value);
        return $this;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return data_get($this->props, "attributes.$name", $default);
    }

    public function removeAttribute(string $name): static
    {
        $this->setProperty("attributes.$name", null);
        return $this;
    }

    public function getProperty(string $typeOrKey, ?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return data_get($this->props, $typeOrKey, $default);
        } else {
            return data_get($this->props, "$typeOrKey.$key", $default);
        }
    }

    public function properties(): array
    {
        return $this->props;
    }

    public function addManufacturerCode(string|array $code): self
    {
        if (is_string($code)) $code = [$code];
        $codes = $this->getProperty("m_codes", default: []);
        $codes = array_merge($codes, $code);

        $arr = array_unique($codes);
        $arr = Arr::map($arr, fn($v) => preg_replace('/ар\./u', '', $v));

        $arr = Arr::reject($arr, fn($v) => in_array($v, [$this->article]));

        return $this->setProperty('m_codes', $arr);
    }

    public function setStarsAttribute(int $count): self
    {
        return $this->setProperty('stars', $count);
    }

    public function replaceWords(array $from, ?array $to = null): self
    {
        if (is_null($to)) {
            $to = array_values($from);
            $from = array_keys($from);
        }

        $from = Arr::map($from, fn($v) => $v);
        $to = Arr::map($to, fn($v) => " $v ");

        $this->tempName = $this->tempName->replace($from, $to, false)->squish()->trim();

        return $this;
    }

    public function removeWords(array $words): self
    {
        $this->tempName = $this->tempName->remove($words)->squish()->trim();
        return $this;
    }

    public function replaceRegex(string|array $regex, string|callable $callback): self
    {
        if (is_string($regex)) $regex = [$regex];

        foreach ($regex as $rgx) {
            if (!is_callable($callback)) {
                $callback = fn() => $callback;
            }
            if (!str($rgx)->startsWith(['/', '#', '~'])) {
                $rgx = str($rgx)->replace('/', '\/')->surround('/')->append('u')->trim()->value();
            }

            $this->tempName = $this->tempName->replaceMatches($rgx, $callback)->squish()->trim();
        }

        return $this;
    }

    protected function addPropInternal(string $key, array|string $vals): self
    {
        if (is_string($vals)) $vals = [];
        $values = $this->getProperty($key, default: []);
        $values = array_merge($values, (array_is_list($vals) ? $vals : [$vals]));

        return $this->setProperty($key, $values);
    }

    public function addDimensions(array $values): self
    {
        return $this->addPropInternal('dimensions', $values);
    }

    public function addSeries(array $values): self
    {
        $series = $this->getAttribute('series', []);
        if (isset($values['type'])) {
            $values['type'] = str($values['type'])->replaceMatches('/\d/u', '')->trim()->value();
        }

        return $this->setAttribute('series', array_merge($series, $values));
    }

    public function addColors(array $values): self
    {
        $values = Arr::map($values, function ($v) {
            $v = str($v);
            if ($v->contains('/')) {
                return $v->explode('/')->values()->flatten()->toArray();
            } else {
                return $v->value();
            }
        });

        return $this->addPropInternal('colors', Arr::flatten($values));
    }
}
