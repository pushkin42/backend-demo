<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Factories;

use Illuminate\Support\LazyCollection;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Importers\BX_BaseImporter;
use Pushkin42\LaravelHelpers\CommerceML\Classes\Importers\BX_GoodsImporter;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_FileImporterInterface;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_IncomingFileDataInterface;

class BX_FileImporterFactory
{
    protected array $supports = [
        BX_GoodsImporter::class,
    ];

    public static function make(LazyCollection $fileData): ?BX_FileImporterInterface
    {
        $c = new static();
        foreach ($c->supports as $importer) {
            if (!class_exists($importer)) continue;
            /**
             * @var BX_BaseImporter $cl
             */
            $cl = new $importer($fileData);
            if ($cl->supports()) {
                return $cl;
            }
        }

        return null;
    }
}
