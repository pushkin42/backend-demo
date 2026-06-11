<?php

namespace App\Actions\LLM\Rules;

use App\Actions\LLM\Contexts\ProductContext;

abstract class BaseRule implements ProductRuleInterface
{
    use RulesFuncTrait;

    protected array $colors = [
        'сер.' => 'серый',
        'зел.' => 'зелёный',
        'зел' => 'зелёный',
        'коф' => 'кофейный',
        'т.сер' => 'тёмно-серый',
        'бел/син' => 'белый/синий',
        'беж/бел' => 'бежевый/белый',
        'св беж' => 'светло-бежевый',
        'ч/сир' => 'черный/сиреневый',
        'св.жел' => 'светло-желтый',
        'корич' => 'коричневый',
        'квадр' => 'квадратные',
        'зол' => 'золотая',
        'син/сер' => 'синий/серый',
        'мал' => 'малиновый',
        'беж' => 'бежевый',
        'син.' => 'синий',
        'сир' => 'сиреневый',
        'сер/беж' => 'серый/бежевый',
        'сер' => 'серый',
        'ваниль' => 'бежевый',
        'перс' => 'персиковый',
        'ч/сер' => 'черный/серый',
        'сер-сир' => 'серый/сиреневый',
        'ч/бел/т.сер' => 'черно-белый/темно-серый',
        'бел' => 'белый',
        'гол' => 'голубой',
        'син' => 'синий',
        'кор' => 'коричневый',
        'черн' => 'черный',
        'серебр' => 'серебристый',
        'кофе' => 'коричневый',
        'шокол' => 'коричневый',
        'песоч' => 'песочный',
        'крем' => 'кремовый',
        'олив' => 'оливковый',
        'голуб' => 'голубой',
        'красный' => 'красный',
        'бордо' => 'бордовый',
        'Желтый' => 'желтый',
        'Салатовый' => 'салатовый',
    ];

    public function extractSeries(ProductContext $context): ProductContext
    {
        $isList = array_is_list($this->seriesRegex);

        foreach ($this->seriesRegex as $regex => $value) {
            $isClass = class_exists($value);

            if ($isClass) {
                $context = new $value()->handle($context);
            } else {
                if ($isList) {
                    if ($this->isValidRegex($value)) {
                        // убираем найденное
                        $context = $context->replaceRegex($value, '');
                    } else {
                        $context = $context->removeWords($value);
                    }
                } else {
                    // ключ-значение подразумевает ключ-регулярку и значения-массив или строку
                    $context = $context->replaceRegex($regex, $value);
                }
            }
        }

        return $context;
    }

}
