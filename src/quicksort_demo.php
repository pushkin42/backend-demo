<?php
/**
* Реализация сортирует массив по ссылкам, элементы переставляются внутри исходного массива, без создания дополнительных массивов left/right на каждом шаге.
* Поэтому расход дополнительной памяти минимален.
**/

function quicksort(array &$arr, int $left = 0, ?int $right = null): void
{
    $right ??= count($arr) - 1;

    if ($left >= $right) {
        return;
    }

    $i = $left;
    $j = $right;
    $pivot = $arr[intdiv($left + $right, 2)];

    while ($i <= $j) {
        while ($arr[$i] < $pivot) {
            $i++;
        }

        while ($arr[$j] > $pivot) {
            $j--;
        }

        if ($i <= $j) {
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
            $i++;
            $j--;
        }
    }

    if ($left < $j) {
        quicksort($arr, $left, $j);
    }

    if ($i < $right) {
        quicksort($arr, $i, $right);
    }
}

function array_random_values(int $count, int $min = 0, int $max = 10000000): array
{
    $ret = [];
    for ($i = 0; $i < $count; $i++) {
        $ret[] = mt_rand($min, $max);
    }
    return $ret;
}
