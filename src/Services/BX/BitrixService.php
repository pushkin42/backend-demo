<?php

namespace App\Services\BX;

use App\DTO\BX\BX_ParsedFileData;
use App\Enums\BX_FileType;
use App\Exceptions\BX\BX_FileNotFoundException;
use App\Models\BX\BitrixImportFileState;
use App\Models\BX\BitrixImportState;
use App\Services\BaseAppService;
use App\Services\BX\Parsers\GoodsParser;
use App\Services\BX\Parsers\GroupsParser;
use App\Services\BX\Parsers\PriceListParser;
use App\Services\BX\Parsers\PricesParser;
use App\Services\BX\Parsers\PropertiesGoodsParser;
use App\Services\BX\Parsers\RestsParser;
use App\Services\BX\Parsers\StoragesParser;
use App\Services\BX\Parsers\UnitsParser;
use App\Services\User\UserIdentificationServiceInterface;
use App\Traits\HasCustomLoggerTrait;
use App\Traits\InteractsWithBitrixDataTrait;
use Exception;
use finfo;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use InvalidArgumentException;
use SplFileInfo;
use Storage;
use Throwable;

class BitrixService extends BaseAppService implements BitrixServiceInterface
{
    use HasCustomLoggerTrait, InteractsWithBitrixDataTrait;

    protected string $storageName;
    protected string $exchangePath = "exchange";

    public function __construct(
        protected readonly UserIdentificationServiceInterface $userIdentificationService,
    )
    {
        $this->storageName = config('bx.storage_name', 'bx');
        $this->initializeLogger('bx');
    }

    public function getFileContentType(string|SplFileInfo $filename, $content = null): ?BX_FileType
    {

        $sessionId = $this->getSessionId();

        $content = $this->prepareContent($filename, $content);

        $m = (cache2()->redis()->sMembers("bx:exchange:file_type:$sessionId"));

        if (is_array($m)) {
            foreach ($m as $item) {
                $f = null;
                $t = null;
                try {
                    [$f, $t] = explode(':', $item);
                } catch (Throwable) {
                }
                if ($f && $t) {
                    $fn = $this->resolveFilename($filename);
                    if ($fn === $f && $t = BX_FileType::tryFrom($t)) {
                        return $t;
                    } else {
                        cache2()->redis()->srem("bx:exchange:file_type:$sessionId", $f);
                    }
                }
            }
        }

        try {
            $xml = rescue(fn() => $this->xml2array($filename, $content));

            if (!is_array($xml)) {
                return null;
            }
            $fn = function () use ($xml) {

                if ($catalogData = data_get($xml, 'Каталог')) {
                    if (data_has($catalogData, 'Товары.Товар')) {
                        return BX_FileType::BX_FILE_TYPE_GOODS;
                    }
                }
                if ($classifierData = data_get($xml, 'Классификатор')) {
                    if (data_has($classifierData, 'Группы.Группа')) {
                        return BX_FileType::BX_FILE_TYPE_GROUPS;
                    }
                    if (data_has($classifierData, 'ТипыЦен.ТипЦены')) {
                        return BX_FileType::BX_FILE_TYPE_PRICE_LIST;
                    }
                    if (data_has($classifierData, 'Свойства.Свойство')) {
                        return BX_FileType::BX_FILE_TYPE_PROPERTIES_GOODS;
                    }
                    if (data_has($classifierData, 'Склады.Склад')) {
                        return BX_FileType::BX_FILE_TYPE_STORAGES;
                    }
                    if (data_has($classifierData, 'ЕдиницыИзмерения.ЕдиницаИзмерения')) {
                        return BX_FileType::BX_FILE_TYPE_UNITS;
                    }
                }
                if ($goodsPack = data_get($xml, 'ПакетПредложений')) {
                    if ($p = data_get($goodsPack, 'Предложения.Предложение')) {
                        $this->ensureDataIsCorrectArray($p);
                        $test = head($p);

                        if (data_has($test, 'Цены.Цена')) {
                            return BX_FileType::BX_FILE_TYPE_PRICES;
                        }
                        if (data_has($test, 'Остатки.Остаток')) {
                            return BX_FileType::BX_FILE_TYPE_RESTS;
                        }
                        if (data_has($test, 'Длина') && data_has($test, 'Ширина')) {
                            return BX_FileType::BX_FILE_TYPE_GOODS_WH;
                        }
                    }
                }
                return null;
            };

            $ft = rescue($fn);
            if ($ft instanceof BX_FileType) {
                cache2()->redis()->sAdd("bx:exchange:file_type:$sessionId", "$filename:$ft->value");
                cache2()->redis()->expire("bx:exchange:file_type:$sessionId", 20 * 60);
            }

            return $ft;

        } finally {
            unset($xml);
        }
    }

    public function getSessionId(): string
    {
        return session()->getId();
    }

    private function prepareContent(string|SplFileInfo $filename, $content = null)
    {
        if (is_file($filename) && file_exists($filename)) {
            return cache2()->remember("bx:exchange:raw_file_content:$filename", fn() => file_get_contents($filename), now()->addHours(2));
        }

        if ($content === null) {
            if ($filename instanceof SplFileInfo) {
                $fn = $filename->getRealPath();
                $content = fn() => file_get_contents($fn);
            } else {
                $content = fn() => Storage::disk($this->storageName)->get($this->makeRelativePath($filename));
                $fn = $filename;
            }
        } else {
            $fn = ($filename instanceof SplFileInfo) ? $filename->getRealPath() : $filename;
        }

        return cache2()->remember("bx:exchange:raw_file_content:$fn", $content, now()->addHours(2));
    }

    private function makeRelativePath(string $filename): string|bool
    {
        $filename = str($filename);
        if ($filename->contains(['../', './'])) {
            throw new InvalidArgumentException("Path traversable detected");
        }

        $sessionId = $this->getSessionId();
        if (!Storage::disk($this->storageName)->directoryExists("$this->exchangePath/$sessionId")) {
            Storage::disk($this->storageName)->makeDirectory("$this->exchangePath/$sessionId");
        }
        $p = "$this->exchangePath/$sessionId";
        if (Storage::disk($this->storageName)->directoryExists($p)) {
            return "$p/$filename";
        } else {
            return false;
        }

    }

    private function resolveFilename(string|SplFileInfo $filename): string
    {
        return (is_string($filename) ? $filename : basename($filename));
    }

    private function xml2array($filename, $content = null, $root = null)
    {
        $sessionId = $this->getSessionId();
        $content = $this->prepareContent($filename, $content);

        // почему simplexml_load_string? класс обрабатывает файлы не более 2Мб (статистически)
        // для более объемных файлов можно заменить на LazyCollection, но тогда поменяется контекст обработки - файл не получится положить в кеш целиком

        $ret = cache2()->remember(
            name: "bx_file_content:$sessionId:$filename",
            value: fn() => json_decode(json_encode(simplexml_load_string($content)), true),
            ttl: now()->addMinutes(5)
        );

        return ($root) ? data_get($ret, $root) : $ret;
    }

    public function saveFile(string $filename, $content): bool|string
    {
        $fn = $this->makeRelativePath($filename);
        if ($fn===false) return false;
        try {
            if (Storage::disk($this->storageName)->put($fn, $content)) {
                return Storage::disk($this->storageName)->path($fn);
            } else {
                return false;
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }

    public function getFileFormat(string $filename, $content = null): ?string
    {
        $content = $this->prepareContent($filename, $content);

        try {
            $fInfo = new finfo(FILEINFO_MIME_TYPE);
            $fileFormat = $fInfo->buffer($content);
            return strtolower($fileFormat);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
            return null;
        } finally {
            unset($fInfo);
        }
    }

    public function parseFile(string $filename, BX_FileType $fileType): BX_ParsedFileData|false
    {
        $disk = Storage::disk($this->storageName);
        if (str($filename)->startsWith('/')) {
            $fn = $filename;
            $absolute = true;
        } else {
            $fn = $this->makeRelativePath($filename);
            $absolute = false;
        }

        try {
            if(!$fn) return false;

            if (!$absolute && !$disk->fileExists($fn)) {
                throw new FileNotFoundException("Файл не существует в хранилище: $filename");
            }

            if (!$absolute && ($disk->fileSize($fn) === 0)) {
                return false; //
            }

            $parseFn = fn(string $filename, string $root) => $this->xml2array(filename: $filename, root: $root);

            $data = match ($fileType) {
                BX_FileType::BX_FILE_TYPE_GOODS => app(GoodsParser::class)->parse($this->xml2array(filename: $filename, root: 'Каталог.Товары.Товар')),
                BX_FileType::BX_FILE_TYPE_GROUPS => app(GroupsParser::class)->parse($parseFn($filename, 'Классификатор.Группы.Группа')),
                BX_FileType::BX_FILE_TYPE_PRICE_LIST => app(PriceListParser::class)->parse($parseFn($filename, 'Классификатор.ТипыЦен.ТипЦены')),
                BX_FileType::BX_FILE_TYPE_PRICES => app(PricesParser::class)->parse($parseFn($filename, 'ПакетПредложений.Предложения.Предложение')),
                BX_FileType::BX_FILE_TYPE_PROPERTIES_GOODS => app(PropertiesGoodsParser::class)->parse($parseFn($filename, 'Классификатор.Свойства')),
                BX_FileType::BX_FILE_TYPE_RESTS => app(RestsParser::class)->parse($parseFn($filename, 'ПакетПредложений.Предложения.Предложение')),
                BX_FileType::BX_FILE_TYPE_STORAGES => app(StoragesParser::class)->parse($parseFn($filename, 'Классификатор.Склады')),
                BX_FileType::BX_FILE_TYPE_UNITS => app(UnitsParser::class)->parse($parseFn($filename, 'Классификатор.ЕдиницыИзмерения')),
                default => false // в enum есть еще варианты, но обработчиков для них не нужны
            };

            if (is_array($data)) {
                return BX_ParsedFileData::makeFrom(fileType: $fileType, contents: $data);
            } else {
                return false;
            }
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
            return false;
        }
    }

    public function isXML($content): bool
    {
        return str_contains($content, '<?xml');
    }

    /**
     * @throws Exception
     */
    public function registerFile(?BitrixImportState $importState, string $filename, ?BX_FileType $fileType, $content = null): BitrixImportFileState
    {
        $sessionId = $this->getSessionId();
        $content = $this->prepareContent($filename, $content);
        $contentLength = strlen($content);

        $fileType ??= $this->getFileContentType($filename, $content);

        if (!is_file($filename)) {
            if (!Storage::disk($this->storageName)->exists("exchange/$sessionId/$filename")) {
                $this->clearCacheForFile($filename);
                throw new BX_FileNotFoundException($filename);
            }
        }


        $ret = BitrixImportFileState::query()->firstOrCreate(
            [
                'hash_sha256' => hash('sha256', $content),
            ],
            [
                'bitrix_import_state_id' => $importState?->id,
                'session_id' => $sessionId,
                'user_id' => $this->userIdentificationService->getUser()?->getUserId(),
                'file_name' => basename($filename),
                'storage_name' => config('bx.storage_name'),
                'file_size' => $contentLength,
                'file_content_type' => $fileType,
                'file_type' => BX_FileType::getFileClassName($fileType),
                'state' => 'loaded',
                'done' => false,
            ]
        );
        if ($ret instanceof BitrixImportFileState && $ret->wasRecentlyCreated) {
            $importState?->incrementStats('file');
        }
        return $ret;
    }

    private function clearCacheForFile(string $filename): void
    {
        $sessionId = $this->getSessionId();
        cache2()->forget("bx:exchange:raw_file_content:$filename");
        cache2()->redis()->sRem("bx:exchange:file_type:$sessionId", $filename);
        cache2()->forget("bx_file_content:$sessionId:$filename");
    }
}
