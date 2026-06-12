<?php

namespace App\Actions\LLM\Rules;

use App\Actions\LLM\Contexts\ProductContext;
use Illuminate\Support\Arr;
use Throwable;

abstract class ProductGroupRule extends BaseRule
{
    /**
     * Ключевые слова для фильтра исходного файла
     */
    protected array $keywords = [];

    /**
     * Ключевые слова, товары с которыми нужно исключить из обработки
     */
    protected array $badKeywords = [];

    /**
     * Категории, в которые должны входить товары
     */
    protected array $categories = [];

    /**
     * Аббревиатуры для замены (ключ-исходное, значение-целевое)
     */
    protected array $abbreviations = [];

    /**
     * Массив замен сразу после поступления контекста в обработку
     */
    protected array $precleanStrings = [];

    /**
     * Массив регулярных выражений для сопоставления кодов поставщика и служебных полей
     */
    protected array $manufacturerCodeRegex = [];

    /**
     * Массив ключей для подмены единиц измерения (от 1 до 4 значений, например, width/height/depth)
     */
    protected array $dimensionKeys = [];

    protected function addStarsDescription(ProductContext $context): ProductContext
    {
        /**
         * 1 звезда это товар на складе хранения т.е с условием возврата непроданного заводу
         * две звезды это заказная позиция или та которая продается не очень
         * три звезды это товар снятый с производства (старая коллекция)
         */

        $descr = [
            1 => 'storage_returnable',
            2 => 'order_or_slow',
            3 => 'discontinued',
        ];

        $stars = $context->getProperty('stars');
        if ($stars) {
            $context->setProperty('stars', ['stars' => $stars, 'description' => $descr[$stars] ?? 'unknown']);
        }

        return $context;
    }

    protected function applyAfterParent($context): ProductContext
    {
        return $context;
    }

    public function apply(ProductContext $context): ProductContext
    {
        $context = $this->precleanName($context, $this->precleanStrings);

        $context = $this->applyAfterParent($context);

        $context = $this->extractStars($context);
        $context = $this->addStarsDescription($context);
        $context = $this->extractRealManufacturerName($context);
        $context = $this->extractManufacturerCode($context);

        // ... дальнейший pipeline обработки данных для других типов товаров

        return $context;
    }

    protected function matchMeasure(string $measure, string $k, mixed $v): array
    {
        return match ($measure) {
            'см' => [$k => round($v / 100, 3)],
            'мм', 'мл' => [$k => round($v / 1000, 3)],
            default => [$k => $v], // как есть
        };
    }

    public function extractDimensions(ProductContext $context): ProductContext
    {
        foreach ($this->dimensionsRegex as $key => $regex) {
            if (is_numeric($key)) {
                $key = null;
            }
            if ($key && !str($context->originalName)->contains($key, true)) continue;

            $context->replaceRegex($regex, function ($matches) use ($regex, &$context) {
                $matches = $this->replaceNumberDots($this->rejectNumericKeys($matches));
                $measure = str(data_get($matches, 'measure', 'м'))->lower()->trim()->value();
                $type = mb_strtolower(trim(data_get($matches, 'type')));

                if ($type) {
                    $matches['type'] = match ($type) {
                        'd', 'д' => 'diameter',
                        default => $type,
                    };
                }

                $matches = Arr::mapWithKeys($matches, function ($v, $k) use ($measure, &$transformedMeasure, $matches) {
                    // это не значение
                    if (!str($k)->startsWith('val')) return [$k => $v];
                    if (empty($v)) $v = null;

                    try {
                        if (str($v)->contains('-')) {
                            // интервал
                            [$v1, $v2] = explode('-', $v);
                            $m1 = $this->matchMeasure($measure, $k, $v1);
                            $m2 = $this->matchMeasure($measure, $k, $v2);

                            $ret = [$m1, $m2];

                        } else {
                            $ret = $this->matchMeasure($measure, $k, $v);
                        }
                    } catch (Throwable $e) {
                        dd($matches, $e, $k, $v, $measure);
                    }

                    $transformedMeasure = match ($measure) {
                        'см', 'мм' => 'м',
                        'мл' => 'л',
                        default => $measure,
                    };

                    return $ret;
                });

                $context->addDimensions(array_merge($matches, ['transformed_measure' => $transformedMeasure]));

            });
        }

        return $context;
    }


    public function cleanup(ProductContext $context): ProductContext
    {
        $context->removeWords(['()', ' / ']);
        $context->replaceRegex('(?:[;№]|(?:диам\.?, h,)|арт.$)$', '');

        $context->replaceRegex('/[^А-яёЁ -\/]/u', function ($matches) use (&$context) {
            dd($matches, $context->properties(), $context->tempName->value(), $context->originalName);
        });
        $context->tempName = null;
        $context->setProperty('parsed_data', null);
        $context->cleanupProps();

        return $context;
    }

    private function replaceEntries($regex, $context, $pr): ProductContext
    {
        try {
            return $context->replaceRegex($regex, function ($matches) use (&$context, $pr, $regex) {
                $context->addManufacturerCode($matches);
                return '';
            });
        } catch (Throwable $e) {
            dd($e, $regex);
        }
    }

    public function extractManufacturerCode(ProductContext $context, array $matches = []): ProductContext
    {

        if (isset($this->manufacturerCodeRegex['global'])) {
            foreach ($this->manufacturerCodeRegex['global'] as $pr => $regex) {
                $context = $this->replaceEntries($regex, $context, $pr);
            }
        }

        $r = Arr::except($this->manufacturerCodeRegex, 'global');

        foreach ($r as $pr => $regex) {
            if (empty($regex)) continue;

            $cm = count($matches) && (
                    in_array($pr, $matches) ||
                    str($context->originalName)->contains($matches, true) ||
                    str($context->tempName)->contains($matches, true) ||
                    str($context->manufacturerName)->contains(array_merge($matches, [$pr]), true)
                );

            if (
                str($context->originalName)->contains($pr, true) ||
                str($context->tempName)->contains($pr, true) || $cm || $pr == 'global'
            ) {
                $context = $this->replaceEntries($regex, $context, $pr);
            }
        }


        return $context;
    }

    protected function parseOtherStars(ProductContext $context): void
    {
        $exStars = $context->tempName->substrCount('*');

        if ($exStars) {
            $context->tempName = $context->tempName->remove('*')->squish()->trim();
            $s = $context->getProperty('stars', 0) + $exStars;
            $context->setProperty('stars', $s);
        }
    }

    public function extractColors(ProductContext $context): ProductContext
    {
        $ck = array_keys($this->colors);
        $cv = array_values($this->colors);

        $ck = Arr::map($ck, fn($v) => str($v)->replace('/', '\/')->trim()->value());
        $cv = Arr::map($cv, fn($v) => str($v)->replace('/', '\/')->trim()->value());

        $p = str('#(?<colors>(' . implode('\b|', array_merge($ck, $cv)) . '))#ui')
            ->replace('.', '\.')
            ->trim()
            ->value();

        if (preg_match_all($p, $context->tempName->value(), $matches)) {
            $matches = ($this->rejectNumericKeys($matches))['colors'];
            $colors = [];
            foreach ($matches as $match) {
                $match = trim($match);
                if ($match == 'зели') continue;
                if (isset($this->colors[$match])) {
                    $colors[] = trim($this->colors[$match]);
                } else {
                    $colors[] = $match;
                }
            }
            $ca = array_unique(Arr::flatten($colors));
            $ca = $this->rejectEmptyFields($ca);
            $ca = Arr::map($ca, fn($v) => mb_strtolower(trim($v)));

            $context->addColors($ca);
        }

        return $context;
    }

    public function supports(ProductContext $context): bool
    {
        if (count($this->keywords)) {
            $kw = implode('|', $this->keywords);
            $kw = "($kw)";
        } else {
            $kw = '.*';
        }
        $rgx = "/\b$kw\b/ui";

        if (count($this->badKeywords)) {
            $kw = implode('|', $this->badKeywords);
            $kw = "($kw)";
            $nrgx = "#$kw#ui";
        }

        $cc = false;//$context->categoryContains($this->categories);
        $allow = preg_match($rgx, $context->originalName) || $cc;

        $deny = isset($nrgx) && preg_match($nrgx, $context->originalName);

        return ($allow && !$deny);
    }

    public function extractRealManufacturerName(ProductContext $context): ProductContext
    {
        $ms = implode('|', array_unique(array_merge($this->manufacturers, array_keys($this->manufacturerCodeRegex))));

        $context->replaceRegex("/(?<mn>$ms)/u", function ($matches) use ($context) {
            $mn = data_get($matches, 'mn');
            if ($mn) {
                $context->setProperty('real_manufacturer', $mn);
            }
            return '';
        });

        return $context;
    }

    protected function extractStars(ProductContext $context): ProductContext
    {
        $context->tempName = str($context->tempName)->replaceMatches('/\*{2,4}/', function ($matches) use (&$context) {
            $s = rescue(fn() => substr_count($matches[0] ?? '', '*'));
            $context->setStarsAttribute((int)$s);
            return '';
        })->squish()->trim();

        return $context;
    }

    public function precleanName(ProductContext $context, array $matches): ProductContext
    {
        foreach ($matches as $k => $v) {
            if (!str($k)->startsWith(['/', '#'])) {
                $k = str("$k")->replace('/', '\/')->trim()->surround('#')->append('u')->value();
            }
            $context = $context->replaceRegex($k, $v);
        }

        return $context;
    }

}
