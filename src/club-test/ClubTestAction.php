<?php

namespace App\Actions;

use Exception;
use Faker\Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

final readonly class ClubTestAction
{

    public function __construct(
        protected readonly Generator $faker
    )
    {
    }

    /**
     * Формирователь данных для выгрузки
     * @return array
     */
    protected function fakeSeed(): array
    {
        $ret = [];

        for ($i = 0; $i <= 500; $i++) {
            $ret[] = [
                'last_name' => $this->faker->lastName('male'),
                'first_name' => $this->faker->firstName('male'),
                'phone' => $this->faker->phoneNumber(),
                'email' => $this->faker->email(),
            ];
        }

        return $ret;
    }

    /**
     * Заполнить коллекцию данными из нужного источника
     * @return LazyCollection
     */
    protected function seed(): LazyCollection
    {
        return LazyCollection::make($this->fakeSeed());
    }

    /**
     * Создать отчет о пользователях из базы данных
     * @param string $targetFilename
     * @return bool
     */
    public function makeReport(string $targetFilename): bool
    {
        // data может прилететь откуда угодно, хоть из БД, хоть из Redis...
        $data = $this->seed();

        try {
            $f = fopen($targetFilename, 'w');

            fputcsv($f, ['last_name', 'first_name', 'phone', 'email']);
            $data->each(function ($item) use ($f) {
                fputcsv($f, $item);
            });
        } catch (Exception $e) {
            Log::error("Ошибка формирования отчета: {$e->getMessage()}");
            @unlink($targetFilename);
            return false;
        } finally {
            if (isset($f) && is_resource($f)) {
                fclose($f);
            }
        }

        return file_exists($targetFilename) && filesize($targetFilename) > 0;
    }

}
