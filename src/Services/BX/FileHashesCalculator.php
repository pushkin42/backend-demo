<?php

namespace App\Helpers\BX;

use App\Exceptions\BX\BX_FileNotFoundException;
use App\Services\BX\Helpers\CacheArrayAdapter;
use App\Services\Global\Image\ImageServiceInterface;
use InvalidArgumentException;
use Jenssegers\ImageHash\Hash;
use Storage;

class FileHashesCalculator
{
    public ?string $hash_sha256 = null;
    public ?string $hash_sha1 = null;
    protected ?Hash $pHash = null;
    protected array $imageParams = [];

    /**
     * @throws BX_FileNotFoundException
     */
    public function __construct(
        private CacheArrayAdapter $cache,
        private ImageServiceInterface $imageService,
        protected string $filename,
        protected readonly ?string $disk = null
    )
    {
        if (empty($this->filename)) {
            throw new InvalidArgumentException("Не передано имя файла для расчета хешей");
        }

        if ($disk) {
            $disk = Storage::disk($disk);
            if (!$disk->exists($this->filename)) {
                throw new BX_FileNotFoundException($this->filename);
            }
            $this->filename = $disk->path($this->filename);
        } else {
            if (!file_exists($this->filename)) {
                throw new BX_FileNotFoundException($this->filename);
            }
        }

        $this->hash_sha256 = $this->cache->remember("fh_data:sha256", $this->filename, fn() => hash_file('sha256', $this->filename));
        $this->hash_sha1 = $this->cache->remember("fh_data:sha1", $this->filename, fn() => hash_file('sha1', $this->filename));

        /**
         * @var array|false $is
         */
        if (($is = rescue(fn() => getimagesize($this->filename), [])) !== false) {
            // это картинка, добавим еще pHash
            $this->pHash = rescue(fn() => $this->cache->remember("fh_data:pHashRaw", $this->filename, fn() => $this->imageService->pHashRaw($this->filename)));
            $this->imageParams = $is;
        }
    }

    /**
     * @throws BX_FileNotFoundException
     */
    public static function make(string $filename, ?string $disk = null): self
    {
        return app(self::class, [
            'filename' => $filename,
            'disk' => $disk,
        ]);
    }

    public function getPerceptiveHash(bool $raw = false): Hash|string|null
    {
        if (!$this->pHash) {
            return null;
        }
        return (($raw) ? $this->pHash : $this->pHash->toHex());
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function toArray(): array
    {
        return [
            'sha256' => $this->hash_sha256,
            'sha1' => $this->hash_sha1,
            'pHash' => $this->pHash?->toHex(),
            'pHashBinary' => $this->pHash?->toBits(),
        ];
    }

}
