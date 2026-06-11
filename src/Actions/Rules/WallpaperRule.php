<?php

namespace App\Actions\LLM\Rules;

use App\Actions\LLM\Contexts\ProductContext;
use App\Actions\LLM\Rules\Regex\WallpaperPropsRegex;
use Illuminate\Support\Arr;
use function PHPUnit\Framework\matches;

class WallpaperRule extends ProductGroupRule
{

    protected array $keywords = [
        'обои', 'флиз', 'винил', 'рулон', '1[,.]06\s*[xх*]\s*10', '0[,.]53\s*[xх*]\s*10'
    ];

    protected array $badKeywords = [
        'клей', 'щетина', 'валик', 'клео', 'грунтовка', 'краска', 'коврик'
    ];

    protected array $categories = [
        'обои'
    ];

    protected array $manufacturerCodeRegex = [
        'CASA ALPINA' => [
            '/\d{1,2}-\d{2}-\d{3}-\d{2}/u',
            '/\d{2}-\d{3}-\d{2}/u', //11-121-04
        ],
        'БелВинил' => [
            '/\d{2}-\d{4}-\d{2}\b/u',
            '/\d{4}-\d{2}/u',
            '/\d{2}СБ\d{1}Д?\b/u',
            '/СБ\d{2}ок/u',
            '/\d{2}СБ\d{2}/u',
        ],
        'Фокс' => [
            '/11СБ3/u',
            '/\b\d{4,5}(?:-\d{2})?\b/u',
            '/23[ВТРА0-9]+/u',
            '/\d{6}/u',
            '/ФН\d/u',
            '/17ВФТ1К/u'
        ],
        'Elysium' => [
            '/\d{5,6}Е\b/u',
            '/(?:\d{6})[,)]/u'
        ],
        'EVRODEKOR' => [
            '/\d{4}-\d{2,3}(?:[.,]\d{2})?((?:,\d{2}){1,2})?/u'
        ],
        'Victoria Stenova' => [
            '/\d{6}\b/'
        ],
        'Wall Decor' => [
            '/\d{5}-\d{2}(?:,\s?\d{2}){0,2}/u',
        ],

        'WallSecret' => [
            '/\d{4}-\d{2}(?:,\d{2})?\b/u',//9019-13
        ],
        'АС Вилия' => [
            '/\d{4}-\d{2}\b/u',
            '/Б9-\d{2}\b/u',
            '/[ФБ]1-\d{2}\b/u',
            '/[Фф]\d{2}-\d{2}/u',
            '/[Фф]\d-\d{2}/u',
        ],
        'Саратов' => [
            '/50\d{2}-00\b/u',
            '/(?:ар\.С-\d{1} \(М\))|ар\.(?:[CС-]+\d{1,2}\.?[AА1О3ММКФ]?(?:-\d{2})?)/ui',
            '/\(?фон\d{2}\)?\s*В\d{3}/ui',
            '/В(?:\d{3})/ui',
            '/\d{4}В?\b/u',
            '/Мальта00,04/ui',
            '/\(гортензия 06,01,02,03,04\)/ui', //<-- хз куда это девать
            '/(лаванда02,05)|(фон02,05)|( фон 00,06)|( диана01,06)/ui', //<-- тоже
            '/[ДФ]\d{3}/u',
            '/С-\dВ/u',
            '/(?:арт\.)?С\d{2}/u',
        ],
        'G\'BOYA' => [
            '/\d{6}/u',
        ],
        'Wiganford' => [
            '/2015wАК/u',//2015wАК
        ],

        'Китай' => [
            '/№?\d{4}\b/u',
        ],
        'DECOLOR' => [
            '/П\d{3}/u',
            '/ар.С\d{2}/u'
        ]
    ];

    protected array $precleanStrings = [
        'на флиз.основе' => 'флизелиновые',
        '/VictoriaStenova|Victoria Steno\b/ui' => 'Victoria Stenova',
        '/WalI Decor/ui' => 'Wall Decor',
        '/Сар\./ui' => 'Саратов',
        '/MonteSolaro/u' => 'Monte Solaro',
        '/\([а-я]{4,}\d{2}\)\s/u' => '', // (лаванда07)
        '/перс\b/u' => 'персиковые',
        '/паут\b/u' => 'паутинка',
        '/гор тиснения/u' => 'горячего тиснения',
        '/дек\b/u' => 'декор',
        '/Вертикальный мрамор/u' => '',
    ];


    protected array $abbreviations = [
        'вин-е' => 'виниловые',
        'винил-е' => 'виниловые',
        'на флизе' => 'на флизелине',
        'Флиз' => 'флизелиновые',

        'на бум.основе' => 'на бумажной основе',
        'антиванд.' => 'антивандальный',

        'п/покраску' => 'под покраску',
    ];

    protected array $manufacturers = [
        'CASA ALPINA',
        'Elysium',
        'EVRODEKOR',
        'Victoria Stenova',
        'WallSecret',
        'АС Вилия',
        'Саратов',
        'G\'BOYA', // китай,
        'Wiganford', // китай
        'LiteColor', //китай
        'Ateliero',
        'Китай',
        'МПК'
    ];

    protected array $dimensionsRegex = [
        '/(\b(?<val1>\d{1,4}(?:[.,]\d{1,4})?)[*xх](?<val2>\d{1,4}(?:[.,]\d{1,4})?)(\s?(?<measure>см|м|л)?[.]?\b))/u',
        '/(?<val2>(\d{1,2}))(?<measure>(м|см|мм))|(?<val1>(\d{1,2})(?<measure>м|см|мм))/uJ',
    ];

    protected array $densityRegex = [
        '/(?<density>\d{2,3})(?<ed>(г))\/(?<of>(кв\.м))/ui'
    ];

    protected array $seriesRegex = [
        WallpaperPropsRegex::class
    ];

    protected function applyAfterParent($context): ProductContext
    {
        $context->replaceOriginal('Сар.', 'Саратов');
        $context = $context
            ->replaceRegex('/арт\.\/?/', '')
            ->replaceRegex('/гор[яч .]+?\.тис[н]?(?:[.ения]+)|гор.тис\b|гор\.тисн\.|гор\.тиснени[ея]|горяч\.\sтиснение|Горяч\.тис/ui', ' горячее тиснение ')
            ->replaceRegex('/\bФлиз|\bфлиз\b/u', 'флизелиновые ')
            ->replaceRegex('/сир\s/u', ' сиреневые ');
        return $context;
    }

    public function apply(ProductContext $context): ProductContext
    {
        $context = parent::apply($context);


        $context = $this->extractDimensions($context);
        $context = $this->extractColors($context);
        $context = $this->extractSeries($context);
        $context = $this->extractDensity($context);
        $context = $this->extractMaterial($context);

        return $context;
    }

    protected function extractMaterial(ProductContext $context): ProductContext
    {
        if (preg_match_all('/(?<base>флиз|бум)|(?<top>винил)/uix', $context->tempName, $matches, PREG_PATTERN_ORDER)) {
            $matches = $this->rejectNumericKeys($matches);
            $matches = $this->rejectEmptyFields($matches);

            $baseArr = array_values(Arr::reject($matches['base'] ?? [], fn($v) => empty(trim($v))));
            $topArr = array_values(Arr::reject($matches['top'] ?? [], fn($v) => empty(trim($v))));

            $base = head($baseArr);
            $top = head($topArr);

            $base = match ($base) {
                'флиз' => 'флизелин',
                'бум' => 'бумага',
                default => $base
            };

            $top = match ($top) {
                'винил' => 'винил',
                default => $top
            };

            $context->setAttribute('material', $this->rejectEmptyFields(['base' => $base, 'top' => $top]));
        }

        return $context;
    }

    public function cleanup(ProductContext $context): ProductContext
    {
        $context = $context->replaceWords(array_merge($this->abbreviations))->removeWords([$context->article]);
        $n = ['\/'];
        $r = '/^(' . implode('|', $n) . ')/u';
        $context->tempName = $context->tempName->replaceMatches($r, '')->trim();

        $context = $context->replaceRegex('/сер\./', '')->removeWords(['()', '( )', ' / ', '//']);

        $this->parseOtherStars($context);

        $prepend = str('');

        if ($context->tempName->contains($this->categories, true)) {
            $context->replaceRegex('/' . implode('|', $this->categories) . '/ui', function ($matches) use (&$m) {
                $m = mb_ucfirst(head($matches));
                return '';
            });
            if ($m) $prepend = $prepend->prepend($m, ' ');
        } else {
            $prepend = $prepend->prepend(mb_ucfirst(head($this->categories)), ' ');
        }

        $n = trim($context->getAttribute('series.name'));
        if (!$n) {
            $nn = $context->getProperty('collection.0');
            if ($nn) {
                $n = trim($nn);
                $context->setProperty('collection.0', null);
            }
        }

        $n = ($n) ? " \"$n\" " : "";

        $prepend = $prepend->append($n);

        $tt = trim($context->getAttribute('series.type'));
        if ($tt) {
            $prepend = $prepend->append(" ($tt) ");
        }

        $context->unparsed_tokens = $context->tempName->matchAll('/[^А-яЁё ]+/u')->toArray();
        $context->cleanName = $context->tempName->trim()->squish()->value();
        $context->realManufacturerName = $context->getProperty('real_manufacturer');
        $context->setProperty('real_manufacturer', null);

        $fl = $context->getProperty('dimensions.0') ?? $context->getProperty('dimensions');
        $de = data_get($fl, 'transformed_measure');
        $dm = array_unique(Arr::reject(Arr::flatten($fl), fn($v) => !is_numeric($v)));

        $sz = trim(implode('x', $dm) . ' ' . $de);
        if (!empty($sz)) $sz = " ($sz)";

        $c = '#' . implode('|', Arr::map(array_keys($this->colors), fn($v) => "$v\b")) . '#ui';

        $context->cleanName = str($context->cleanName)->prepend($prepend)
            ->append($sz)
            ->remove(['( рис)/'])
            ->replaceMatches($c, '')
            ->squish()->trim()->value();

        $context = parent::cleanup($context);

        $c = $context->getProperty('collection');
        if ($c) {
            $c = array_values($c);
            $context->setProperty('collection', $c);
        }


        return $context;
    }

    public function extractDimensions(ProductContext $context): ProductContext
    {
        $context = parent::extractDimensions($context);

        $dimensions = $context->getProperty('dimensions.0') ?? $context->getProperty('dimensions');

        if (!$dimensions) return $context;

        $dimensions['width'] = data_get($dimensions, 'val1');
        $dimensions['height'] = data_get($dimensions, 'val2');
        $dimensions['measure'] = data_get($dimensions, 'transformed_measure');

        $context->setProperty('dimensions.val1', null);
        $context->overwriteProperty('dimensions', $dimensions);

        return $context;

    }

    public function extractDensity(ProductContext $context): ProductContext
    {

        $context->tempName = $context->tempName->replaceMatches($this->densityRegex, function ($matches) use (&$context) {
            $matches = $this->rejectNumericKeys($matches);

            $context->setDensity($matches['density']);
            return '';
        });

        return $context;
    }


}
