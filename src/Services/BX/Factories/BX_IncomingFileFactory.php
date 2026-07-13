<?php

namespace Pushkin42\LaravelHelpers\CommerceML\Classes\Factories;

use Illuminate\Contracts\Filesystem\Filesystem;
use Pushkin42\LaravelHelpers\CommerceML\Classes\File\BX_LocalIncomingFile;
use Pushkin42\LaravelHelpers\CommerceML\Exceptions\BX_FileNotFound;
use Pushkin42\LaravelHelpers\CommerceML\Interfaces\BX_IncomingFileDataInterface;

class BX_IncomingFileFactory
{
    protected array $supported = [
        BX_LocalIncomingFile::class,
    ];


    /**
     * @throws BX_FileNotFound
     */
    public static function make(string $filename, ?string $diskName = null): ?BX_IncomingFileDataInterface
    {
        $s = (new static)->supported;
        foreach ($s as $supported) {
            if (!class_exists($supported)) continue;
            $c = $supported::make($filename, $diskName);
            if ($c?->supports()) return $c;
        }

        return null;
    }
}
