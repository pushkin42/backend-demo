<?php

namespace App\Services\BX\Helpers;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use IteratorAggregate;
use JsonSerializable;
use Redis;
use Traversable;

class CacheArrayAdapter implements ArrayAccess, Countable, IteratorAggregate, Arrayable, Jsonable, JsonSerializable
{
    private const INDEX_KEY = 'ImportCacheArrayAdapter:keys';

    protected int $redisExpireMinutes = 15;

    private array $data = [];

    public function __construct(
        protected readonly Redis $redis,
    ) {
        $this->loadFromRedis();
    }

    private function loadFromRedis(): void
    {
        $keys = $this->redis->sMembers(self::INDEX_KEY);

        if (!$keys) {
            return;
        }

        $pipeline = $this->redis->pipeline();

        foreach ($keys as $key) {
            $pipeline->hGetAll($key);
        }

        foreach ($pipeline->exec() as $hash) {
            foreach ($hash ?: [] as $field => $rawValue) {
                $this->data[$field] = $this->unpack($rawValue);
            }
        }
    }

    public function remember(string $key, ?string $subKey = null, mixed $value = null): mixed
    {
        $key = $this->makeDataKey($key, $subKey);

        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        if ($value === null) {
            return null;
        }

        $this->data[$key] = is_callable($value) ? $value() : $value;
        $this->saveToRedis($key, $this->data[$key]);

        return $this->data[$key];
    }

    public function has(string $key, ?string $subKey = null): bool
    {
        return array_key_exists(
            $this->makeDataKey($key, $subKey),
            $this->data
        );
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $offset = (string) $offset;

        if (array_key_exists($offset, $this->data)) {
            return $this->data[$offset];
        }

        $rawValue = $this->redis->hGet(
            $this->getRedisKey($offset),
            $offset
        );

        if ($rawValue === false || $rawValue === null) {
            return null;
        }

        return $this->data[$offset] = $this->unpack($rawValue);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset = (string) $offset;

        $this->data[$offset] = $value;
        $this->saveToRedis($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $offset = (string) $offset;

        unset($this->data[$offset]);

        $redisKey = $this->getRedisKey($offset);

        $this->redis->hDel($redisKey, $offset);
        $this->redis->sRem(self::INDEX_KEY, $redisKey);
    }

    private function saveToRedis(string $key, mixed $value): void
    {
        $redisKey = $this->getRedisKey($key);

        $this->redis->hSet($redisKey, $key, $this->pack($value));
        $this->redis->sAdd(self::INDEX_KEY, $redisKey);

        $ttl = $this->redisExpireMinutes * 60;

        $this->redis->expire($redisKey, $ttl);
        $this->redis->expire(self::INDEX_KEY, $ttl);
    }

    private function getRedisKey(?string $suffix = null): string
    {
        // почему так? а потому, что класс может быть универсальным хранилищем для любого вызывающего.
        // это можно заменить статическим ключом, если класс используется в конкретном контексте.
        // некрасиво? да. работает? тоже да. так зачем страдать?
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = end($trace);

        $class = $caller['class'] ?? 'Default';

        return trim('ImportCacheArrayAdapter:' . $class . ($suffix ? ":$suffix" : ''));
    }

    private function makeDataKey(string $key, ?string $subKey = null): string
    {
        return $subKey ? "{$key}:{$subKey}" : $key;
    }

    private function pack(mixed $value): string
    {
        return serialize($value);
    }

    private function unpack(string $value): mixed
    {
        return rescue(
            fn () => unserialize($value, ['allowed_classes' => true]),
            report: false
        );
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    public function setExpire(int $minutes): self
    {
        $this->redisExpireMinutes = $minutes;

        return $this;
    }
}
