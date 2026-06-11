<?php

namespace App\Services\BX\Services;

use App\DTO\BX\BX_PreparedOriginalPicture;
use App\DTO\BX\FileHashesDTO;
use App\Exceptions\BX\BX_CannotCalculateFileHashes;
use App\Exceptions\BX\BX_CannotCreateOutputFile;
use App\Exceptions\BX\BX_FileNotFoundException;
use App\Exceptions\BX\BX_ImageProcessingError;
use App\Models\Catalog\CatalogFile;
use App\Models\Catalog\CatalogItem;
use App\Models\Catalog\CatalogItemCatalogFilePivot;
use App\Models\Media\Media;
use App\Services\BX\Helpers\CacheArrayAdapter;
use App\Services\Global\Image\ImageServiceInterface;
use App\Services\User\UserIdentificationServiceInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Process\Pipe;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Storage;
use Throwable;

class BitrixImportImageService implements BitrixImportImageServiceInterface
{
    protected FilesystemAdapter $storage;

    protected FilesystemAdapter $tempStorage;
    protected FilesystemAdapter $targetStorage;

    public function __construct(
        protected readonly CatalogItem                        $catalogItem,
        protected readonly UserIdentificationServiceInterface $identificationService,
        protected readonly ImageServiceInterface              $imageService,
        protected CacheArrayAdapter                           $cache,
        protected ?string                                     $storageName = null,
        protected ?string                                     $sessionId = null,
    )
    {
        $this->storageName = ($this->storageName) ?: config('bx.storage_name');
        $this->storage = Storage::disk($this->storageName);
        $this->sessionId = ($this->sessionId) ?: session()->getId();

        $this->tempStorage = Storage::disk('pictures_temp');
        $this->targetStorage = Storage::disk(config('bx.new_storage_name'));

    }

    public function getAllPictures(): Collection
    {
        return $this->catalogItem->pictures;
    }

    /**
     * @throws Exception
     */
    public function addPictureToItem(CatalogFile|string $picture, CatalogItem $item, ?string $pictureXmlId = null): ?Media
    {
        try {
            [$oldSiteFile, $absoluteFilename, $dimensions] = $this->addPicture($picture, $pictureXmlId);
        } catch (Exception $e) {
            // если картинка не загрузилась, больше с ней делать мы ничего не можем, информация попадет в лог
            return null;
        }

        if (!$oldSiteFile) {
            throw new Exception("Не удалось добавить файл $picture во внутреннюю БД сайта");
        }

        $basename = $oldSiteFile->file_name;

        $a = [
            'catalog_item_id' => $item->id,
            'catalog_file_id' => $oldSiteFile->id,
        ];
        if ($oldSiteFile->wasRecentlyCreated || !CatalogItemCatalogFilePivot::where($a)->exists()) {
            if (!CatalogItemCatalogFilePivot::updateOrCreate($a)) {
                throw new Exception("Не удалось привязать картинку к item_id: $item->id");
            }
        }

        if (!$this->targetStorage->exists("$basename")) {
            $ss = $this->tempStorage->readStream($basename);
            $this->targetStorage->writeStream($basename, $ss);
        }

        return $oldSiteFile->addMedia($absoluteFilename, backgroundColor: data_get($dimensions, 'color', '#ffffff'))
            ->toMediaCollection('catalog_images');
    }

    /**
     * @throws BX_CannotCalculateFileHashes
     * @throws BX_CannotCreateOutputFile
     */
    public function addPicture(CatalogFile|string $picture, ?string $pictureXmlId = null): array
    {
        $picture = $this->preparePicture($picture);

        $oldSiteStorage = Storage::drive('dom_main_catalog_pictures');

        $basename = $picture->getBasename();

        if (!$oldSiteStorage->exists("000/$basename")) {
            $ss = $this->tempStorage->readStream($basename);
            $oldSiteStorage->writeStream("000/" . $basename, $ss);
        }

        $dimensions = [
            'color' => $picture->getBackgroundColor(),
            'width' => $picture->getWidth(),
            'height' => $picture->getHeight(),
        ];

        $pictureData = [
            'type' => 'catalog_picture',
            'dimensions' => $dimensions,
            'file_size' => $picture->getFileSize(),
            'content_type' => 'image/webp',//!!!
            'drive_name' => 'local',
            'sub_dir' => '000',
            'file_name' => $basename,
            'original_file_name' => $picture->getOriginalBasename(),
            'hash_sha1' => $picture->getHash('target', 'sha1'),
            'xml_id' => $pictureXmlId,
        ];

        $oldSiteFile = null;
        try {
            $oldSiteFile = CatalogFile::firstOrCreate(
                [
                    'hash_sha256' => $picture->getTargetHash('sha256'),
                ],
                $pictureData
            );
        } catch (UniqueConstraintViolationException $e) {
            try {
                $em = str($e->getMessage());
                if ($em->contains('xml_id_unique')) {
                    if ($pictureXmlId) {
                        CatalogFile::query()->where('xml_id', $pictureXmlId)->forceDelete();
                    }
                    $oldSiteFile = CatalogFile::query()->updateOrCreate(['xml_id' => $pictureXmlId], $pictureData);
                }
                if ($em->contains('file_name_unique')) {
                    CatalogFile::query()->where('file_name', $basename)->forceDelete();
                    $oldSiteFile = CatalogFile::updateOrCreate(['hash_sha256' => $picture->getTargetHash()], $pictureData);
                }
                $oldSiteFile->saveQuietly();
            } catch (Throwable $e) {
                dump_local($e->getMessage());
                $oldSiteFile = CatalogFile::query()
                    ->where('hash_sha256', $picture->getTargetHash())
                    ->orWhere('file_name', $basename)
                    ->first();
            }
        }

        if (!$oldSiteFile) {
            throw new Exception("Не удалось создать или обновить запись в БД хранилища старого сайта");
        }

        return [$oldSiteFile, $picture->getFilename(), $dimensions];
    }

    public function preparePicture(CatalogFile|string $picture): BX_PreparedOriginalPicture
    {

        if ($picture instanceof CatalogFile) {
            $p = "$picture->sub_dir/$picture->file_name";
            $picture = Storage::disk('dom_main_catalog_pictures')->path($p);
        }

        if (empty($picture)) {
            throw new InvalidArgumentException("Не переданы данные для обработки");
        }

        $t1 = now();

        $sourcePicturePath = $this->normalizeFilePath($picture);
        $sourceHashes = $this->getFileHashes($sourcePicturePath);

        if (!$sourceHashes) {
            throw new BX_CannotCalculateFileHashes($sourcePicturePath);
        }

        $targetPicturePath = $this->convertSource($sourcePicturePath);
        if (!$targetPicturePath) {
            throw new BX_CannotCreateOutputFile($targetPicturePath);
        }

        $targetHashes = $this->getFileHashes($targetPicturePath);

        $time = round(now()->diffAsCarbonInterval($t1, absolute: true)->totalMilliseconds);

        return BX_PreparedOriginalPicture::make($targetPicturePath, $sourcePicturePath, $targetHashes, $sourceHashes, ['time' => $time]);

    }

    protected function normalizeFilePath(CatalogFile|string $picture): string
    {
        if ($picture instanceof CatalogFile) {
            $pictureDisk = Storage::disk($picture->drive_name);
            $rel = "$picture->sub_dir/$picture->file_name";
            $sourcePicturePath = $pictureDisk->path($rel);

            if (!is_file($sourcePicturePath)) {
                // если файл не существует в ФС сервера, его надо стянуть во временную папку
                if (!$this->tempStorage->writeStream(basename($sourcePicturePath), $pictureDisk->readStream($rel))) {
                    throw new BX_FileNotFoundException($picture);
                }

                $sourcePicturePath = $this->tempStorage->path(basename($sourcePicturePath));
            }

        } else {
            $sourcePicturePath = $this->makeRelativePath($picture);
            $exists = $this->storage->exists($sourcePicturePath);

            if (!$exists) {
                throw new BX_FileNotFoundException("[normalizeFilePath]: $picture");
            }

            $sourcePicturePath = $this->storage->path($sourcePicturePath);
        }

        return $sourcePicturePath;
    }

    private function makeRelativePath(string $path): string
    {
        return "exchange/$this->sessionId/$path";
    }

    /**
     * @throws BX_FileNotFoundException
     */
    private function getFileHashes(string $absoluteFilename): ?FileHashesDTO
    {
        return FileHashesCalculator::make($absoluteFilename);
    }

    public function convertSource(string $filename, ?string $targetFilename = null): ?string
    {
        $targetFilename = str($targetFilename ?? $this->tempStorage->path(md5_file($filename)));

        $targetFilename = $targetFilename->beforeLast('.')
            ->append('.', strtolower(config('bx.catalog.pictures.format', 'webp')))
            ->trim()->value();

        $sh = str(basename($targetFilename))->beforeLast('.')->value();

        if (file_exists($targetFilename) && ($sh === md5_file($filename))) {
            return $targetFilename;
        }

        $w = config('bx.catalog.pictures.width', 800);
        $h = config('bx.catalog.pictures.height', 800);


        $p = $this->runVIPS($targetFilename, $w, $h, $filename);

        return ($p ? $targetFilename : null);
    }

    /**
     * @throws BX_ImageProcessingError
     */
    public function runVIPS(string $targetFilename, mixed $w, mixed $h, string $filename, ?string $bg = null): bool
    {
        putenv("VIPS_CONCURRENCY=1");           // Отключаем многопоточность
        putenv("VIPS_CACHE_MAX=0");             // Отключаем кэш
        putenv("VIPS_WINDOW_BUFFER=100");       // Увеличиваем буфер для больших изображений
        putenv("VIPS_THREAD_STACK=65536");      // Уменьшаем стек потоков

        $pictureInfo = $this->imageService->getPictureInfo($filename);

        $process = Process::pipe(function (Pipe $pipe) use ($pictureInfo, $targetFilename, $w, $h, $filename, $bg) {

            $bgColorRGB = $this->imageService->calculateBackgroundColorForFile($filename, hex: false,
                includingAlpha: $pictureInfo->bands > 3
            );

            $quality = config('bx.catalog.pictures.quality', 85);
            $tp = Storage::disk('tmpfs')
                ->path(
                    str(basename($targetFilename))->beforeLast('.')->append('.vips')->trim()
                );

            $pipe->command([
                'vipsthumbnail',
                $filename,
                '-o',
                $tp,//".webp[Q=$quality,keep=none]",
                '--output-profile',
                'srgb',
                '--size',
                "{$w}x$h",
            ]);

            $bgValue = $bg ?? implode(' ', $bgColorRGB);

            $pipe->command([
                'vips',
                'gravity',
                $tp,
                "{$targetFilename}[Q=$quality]",
                'centre',
                $w,
                $h,
                '--extend',
                'background',
                '--background',
                $bgValue,
            ]);

            $pipe->command([
                'unlink',
                $tp
            ]);

        });


        if ($process->successful()) {
            return true;
        } else {
            $err = str($process->errorOutput())->trim();
            if ($err->contains(['linear', 'vector'])) {
                when(isLocal(), fn() => dump($filename, $err->value()));
                return $this->runVIPS($targetFilename, $w, $h, $filename, '255');
            } else {
                throw new BX_ImageProcessingError($filename, $err->value());
            }
        }
    }
}
