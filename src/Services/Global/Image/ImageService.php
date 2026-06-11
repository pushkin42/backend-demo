<?php

namespace App\Services\Global\Image;

use App\DTO\BX\TPictureInfo;
use App\Exceptions\BX\BX_CannotGetImageInfo;
use App\Exceptions\BX\BX_FileNotFoundException;
use App\Models\Catalog\CatalogCategory;
use App\Models\Catalog\CatalogFile;
use App\Models\Media\Media;
use App\Services\BaseAppService;
use App\Services\Global\Cache\CacheServiceInterface;
use Arr;
use File;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Jenssegers\ImageHash\Hash;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Process;
use Spatie\Image\Enums\ColorFormat;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\Image\Image;
use Storage;

class ImageService extends BaseAppService implements ImageServiceInterface
{

    protected string $placeholder = '';

    public function __construct()
    {
        $this->placeholder = url('images/placeholder.jpg');
    }

    /**
     * @throws FileNotFoundException
     * @throws CouldNotLoadImage
     */
    public function calculateBackgroundColorForFile(string $file, bool $hex = true, bool $includingAlpha = false): string|array
    {

        if (!File::exists($file)) {
            throw new FileNotFoundException("Файл не найден: $file");
        }
        try {
            $image = Image::load($file);
            $picks = [
                $image->pickColor(5, 5, ColorFormat::Hex),
                $image->pickColor($image->getwidth() - 5, $image->getHeight() - 5, ColorFormat::Hex),
                $image->pickColor($image->getwidth() - 5, 5, ColorFormat::Hex),
                $image->pickColor(5, $image->getHeight() - 5, ColorFormat::Hex),
            ];
            $ret = $this->calculateBackgroundColor($picks);
            if ($includingAlpha) {
                $ret = "{$ret}FF";
            }
            $rgba = $this->hex2rgb($ret, $includingAlpha);
            return ($hex) ? $ret : $rgba;
        } finally {
            unset($image);
        }
    }

    public function calculateBackgroundColor(array $imageColors): string
    {

        if ($imageColors === []) {
            return '#ffffff';
        }

        $totalR = 0;
        $totalG = 0;
        $totalB = 0;
        $count = count($imageColors);

        foreach ($imageColors as $hex) {
            $cleanHex = ltrim($hex, '#');
            $totalR += hexdec(substr($cleanHex, 0, 2));
            $totalG += hexdec(substr($cleanHex, 2, 2));
            $totalB += hexdec(substr($cleanHex, 4, 2));
        }

        $avgR = round($totalR / $count);
        $avgG = round($totalG / $count);
        $avgB = round($totalB / $count);

        if ($avgR == 0 && $avgG == 0 && $avgB == 0) {
            return '#ffffff';
        }

        return sprintf("#%02x%02x%02x", $avgR, $avgG, $avgB);
    }

    public function hex2rgb(string $hex, bool $includingAlpha = false): ?array
    {
        $hex = str($hex)->replaceFirst('#', '')->trim();

        if ((!$includingAlpha) && (in_array($hex->length(), [4, 8]))) {
            return null;
        }

        $alphaValue = $hex->substr(-2, 2);
        $hex = $hex->substr(0, 6);

        if ($hex->length() == 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } // Handle standard 6-digit hex codes
        else if (strlen($hex) == 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        } // Invalid hex code length
        else {
            return null;
        }

        $ret = [$r, $g, $b];
        if ($includingAlpha && $alphaValue) {
            $ret[] = hexdec($alphaValue);
        }

        return $ret;

    }

    public function makePictureForCatalogItem(CatalogFile $file, ?string $collectionName = null): string
    {
        $url = str(Storage::disk('dom_main')
            ->path("app/public/images/catalog/$file->sub_dir/$file->file_name"));

        $f = rescue(fn() => file_get_contents($url));
        if (!$f) {
            return $this->placeholder; //placeholder
        }

        $hash = hash('sha256', $url);
        $ext = $url->afterLast('.')->lower()->trim();
        $p = "tmp/catalog_files/$file->id/$hash.$ext";

        try {
            if (Storage::put($p, $f)) {
                $localFile = Storage::path($p);
                $m = $file->addMedia($localFile)->toMediaCollection($collectionName);

                return $m->getAvailableFullUrl([
                    'catalog_item_card_large',
                    'catalog_item_card_small',
                    'catalog_item_card_table'
                ]);
            }
        } finally {
            Storage::delete($p);
        }

        return $this->placeholder;
    }

    public function getMediaUrl(?Media $media, string $conversionName = ''): string
    {
        if (!$media) {
            return $this->getPlaceholderUrl();
        }

        $fn = function () use ($media, $conversionName): string {

            if ($conversionName === '') {
                return $media->getUrl();
            }

            if ($media->hasGeneratedConversion($conversionName)) {
                return $media->getUrl($conversionName);

            } else {
                return $this->getPlaceholderUrl();
            }
        };

        $ret = cache2()->for($this)->tags(['catalog', 'images'])
            ->remember(
                name: "media_url_for_$media->id",
                value: $fn,
                ttl: now()->addMinutes(10)
            );

        return str($ret)
//            ->replace('media/media', 'media/')
            ->trim()->value();
    }

    public function getPlaceholderUrl(): string
    {
        return $this->placeholder;
    }

    public function getCategoryPictureUrl(CatalogCategory $category): string
    {
        $cache = app(CacheServiceInterface::class);
        return $cache->remember(
            name: "catalog:image_url_for_$category->id",
            value: function () use ($category) {
                $category->loadMissing('media');
                $m = $category->media?->first();
                return rescue(fn() => ($m?->getUrl('menu_item_large')) ?? $this->placeholder, $this->placeholder);
            },
            ttl: now()->addMinutes(10),
        );

    }

    private function getImageHasher(): ImageHash
    {
        return new ImageHash(new PerceptualHash());
    }

    public function pHashRaw(string $absoluteFilePath): Hash
    {
        $hasher = $this->getImageHasher();
        return $hasher->hash($absoluteFilePath);
    }

    public function compareHash(string $hash1, string $hash2): int
    {
        $hasher = $this->getImageHasher();
        return $hasher->compare($hash1, $hash2);
    }

    public function updateModelPerceptiveHash(Model $model, string $hashSource, string $dataField = 'phash'): ?string
    {

        if (!is_file($hashSource)) {
            throw new InvalidArgumentException("Файл $hashSource не существует в локальной ФС");
        }

        $phash = $this->pHash($hashSource);

        if ($phash) {
            if (method_exists($model, 'setData')) {
                $model->setData($dataField, $phash, false);
            } else {
                $model->$dataField = $phash;
            }

            return ($model->save()) ? $phash : null;
        } else return null;

    }

    public function pHash(string $absoluteFilePath): string
    {
        $hasher = $this->getImageHasher();
        $hash = $hasher->hash($absoluteFilePath);
        return $hash->toHex();
    }

    public function getPictureInfo(string $filename, $storage = null): TPictureInfo
    {
        if ($storage !== null) {
            if (!Storage::disk($storage)->exists($filename)) {
                throw new BX_FileNotFoundException($filename);
            }
            $filename = Storage::disk($storage)->path($filename);
        } else {
            if (!is_file($filename)) {
                throw new BX_FileNotFoundException($filename);
            }
        }

        return cache2()->for($this)->tags(['catalog', 'images'])
            ->remember(
                name: "picture_info_for_$filename",
                value: function () use ($filename, $storage) {
                    $p = Process::run([
                        'vipsheader',
                        $filename
                    ]);

                    if (!$p->successful()) {
                        throw new BX_CannotGetImageInfo($filename);
                    }

                    $output = str($p->output())->trim();
                    $preg = '/\: ((?<width>(\d+))x(?<height>(\d+))) (?<type>[a-z]+),? ?((?<bands>\d) bands?),? ?((?<profile>(srgb|cmyk|b\-w|grey16|rgb16|multiband|rgb|scRGB|lab))?,? ?(?<driver>\w+))/uim';

                    preg_match_all($preg, $output, $matches, PREG_SET_ORDER);
                    $matches = Arr::only(head($matches), ['width', 'height', 'driver', 'profile', 'type', 'bands']);

                    if ($storage !== null) {
                        data_set($matches, 'storage', $storage);
                    }
                    return TPictureInfo::make($filename, $matches);
                },
                ttl: now()->addMinutes(10),
            );
    }
}
