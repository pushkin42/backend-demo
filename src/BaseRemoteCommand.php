<?php

namespace App\Collector\Commands;

use App\Collector\Commands\Internal\Actions\UpdateServerConfigAction;
use App\Enums\RemoteOS;
use App\Enums\ServerType;
use App\Enums\TimeOverlapEnum;
use App\Interfaces\DatabaseSerializableInterface;
use App\Models\Global\RemoteServer;
use App\Services\SSHClient;
use Arr;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Exception;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use Illuminate\Support\Stringable;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

abstract class BaseRemoteCommand extends BaseCommand
{
    use PeriodicCommandTrait;

    public $uniqueFor = 1; // 1 секунда
    protected ServerType $serverType;
    protected RemoteOS $remoteOS;
    protected mixed $ttl = null;
    protected ?string $cacheKey = null;
    protected ?RemoteServer $remoteServer;
    protected ?SSHClient $sshClient = null;
    protected $updatingCallback = null;
    protected array $postCallbacks = [];
    protected TimeOverlapEnum $onTimeOverlap = TimeOverlapEnum::DEFAULT;
    protected int $retryCount = 1;
    protected int $retryWait = 100;
    protected bool $cancelled = false;
    protected int $iteration = 0;
    protected ?array $sshClientParams = null;
    protected bool $once = false;
    protected int $jitter = 0;
    protected bool $stopOnFailure = false;
    protected $started_at = null, $ended_at = null;

    protected int $executeTimeout = 30; // количество секунд на тайм-аут исполнения
    protected ?string $inputRegex = null; // regex для проверки валидности значения сразу после выполнения
    protected ?string $outputRegex = null; // regex для проверки валидности выходного значения

    public function __construct(
        ?RemoteServer $server = null,
        ?SSHClient    $sshClient = null,
    )
    {
        parent::__construct();

        $this->remoteServer = $server;
        $this->sshClientParams = $sshClient?->toArray();

        $this->applyExternalParams();
    }

    public function applyExternalParams(): self
    {
        $this->serverType = $this->remoteServer->type ?? ServerType::UNKNOWN;
        $this->remoteOS = $this->remoteServer->os ?? RemoteOS::UNKNOWN;

        return $this;
    }

    public static function batchProcess(RemoteServer $server, ?BaseRemoteCommand $command = null, $input = null): mixed
    {
        $p = ($command ?? new static())->setServer($server);
        $ret = $p->process($p->cleanupOutput($input));
        if ($p->transformFunc) {
            $ret = call_user_func($p->transformFunc, $ret);
        }
        $p->result = $ret;
        $p->afterExecuteOperations();

        return $ret;
    }

    public function setServer(RemoteServer $server): static
    {
        $this->remoteServer = $server;
        $this->applyExternalParams();

        return $this;
    }

    protected function afterExecuteOperations(): void
    {

        $this->after();

        if (!$this->updatingModel) {
            $this->updatingModel = $this->remoteServer;
        }

        if ($this->updatingModel && $this->updatingField) {

            $this->updatingModel->timestamps = $this->updatingQuietly ?? true;

            if ($this->updatingCallback) {
                $cb = $this->updatingCallback;
                if (!is_string($cb) && is_callable($cb)) {
                    $this->result = $cb($this->result);
                } else {
                    if (function_exists($cb)) {
                        $this->result = $cb($this->result);
                    } else {
                        throw new Exception("Неизвестная функция для обратного вызова: $cb");
                    }
                }
            }

            $f = str($this->updatingField);
            if ($f->length() && $f->contains('->') && method_exists($this->updatingModel, 'setData')) {
                $dataField = $f->after('->')->trim()->value();
                if ($dataField) {

                    $r = $this->result;
                    if ($r instanceof DatabaseSerializableInterface) {
                        $r = $r->toDatabase();
                    }

                    $this->updatingModel->setData($dataField, $r, true);
                    $this->updatingModel->setActualTimestamp($dataField);
                }
            } else {
                $this->updatingModel->{$this->updatingField} = $this->result;
            }

            if ($this->updatingModel->isDirty($this->updatingField)) {
                $this->updatingModel->save();
            }
        }

        foreach ($this->postCallbacks as $i => $callback) {
            $callback()($this->result);

        }

        foreach ($this->executeAfter ?? [] as $ea) {
            if ($ea) {
                if ($ea instanceof Closure || $ea instanceof SerializableClosure) {
                    call_user_func($ea, $this->remoteServer, $this->result, $this);
                } elseif (class_exists($ea)) {
                    if (method_exists($ea, 'make')) {
                        $ea = $ea::make($this->remoteServer->id, $this->result, $this);
                    } else {
                        $ea = new $ea($this->remoteServer->id, $this->result, $this);
                    }
                    if (is_callable($ea)) {
                        call_user_func($ea, $this->remoteServer->id, $this->result, $this);
                    }
                } else {
                    call_user_func($ea, $this->remoteServer, $this->result, $this);
                }
            }
        }


        if ($this->exitCode === null) $this->exitCode = 0;
        $this->unlock();
    }

    public static function make(?RemoteServer $server = null, ?SSHClient $sshClient = null): static
    {
        return new static($server, $sshClient);
    }

    protected function unlock(): static
    {
        Redis::del("command_lock_{$this->uniqueId()}");
        return $this;
    }

    public static function forBatch(RemoteServer $server, ?SSHClient $ssh = null): static
    {
        return (new static($server, $ssh))->applyExternalParams();
    }

    public function onServer(RemoteServer $server): static
    {
        return $this->setServer($server);
    }

    public function hasServer(): bool
    {
        return $this->remoteServer !== null;
    }

    public function __sleep(): array
    {
        return ['sshClient'];
    }

    public function __wakeup(): void
    {
        if ($this->sshClientParams) {
            $this->sshClient = new SSHClient($this->sshClientParams);
        }
    }

    public function updatingConfig(string|array $param, $callback = null): self
    {
        $serverId = $this->remoteServer->id;

        if (is_array($param)) {
            Arr::mapWithKeys($param, fn($v, $k) => $this->updatingConfig($k, $v));
            return $this;
        }
        if (!is_callable($callback)) {
            $callback = fn() => $callback;
        }
        $c = new UpdateServerConfigAction($serverId, $param, new SerializableClosure($callback));

        $this->registerPostCallback($c);

        return $this;
    }

    protected function registerPostCallback($callback): void
    {
        $this->postCallbacks[] = new SerializableClosure(fn() => $callback);
    }

    public function updatingField(string $field, $callback = null, bool $quietly = false): self
    {
        $this->updatingModel = $this->remoteServer;
        $this->updatingField = $field;
        $this->updatingQuietly = $quietly;
        $this->updatingCallback = (!is_string($callback) && is_callable($callback)) ?
            new SerializableClosure($callback) : $callback;

        return $this;
    }

    public function withCache($ttl = null, $key = null, $key2 = null): self
    {
        $ttl = $ttl ?? Carbon::now()->addSeconds(10);
        if (!($ttl instanceof Carbon)) {
            $ttl = Carbon::now()->addSeconds($ttl);
        }
        $this->ttl = $ttl;
        $this->cacheKey = $key ?? str($this->makeCacheKey())
            ->when($key2, fn($s) => $s->append('_', $key2))->trim()->value();

        return $this;
    }

    private function makeCacheKey(): string
    {
        if (!$this->remoteServer) {
            throw new \Exception("Нужно назначить сервер прежде чем включать кеширование");
        }

        $ip = $this->remoteServer->ip ?? $this->remoteServer->id;
        $key = trim($this->command . (($this->signature) ? "$this->signature:" : "_") . "_{$ip}");
        return md5($key);
    }

    public function getCacheKey(): ?string
    {
        try {
            return $this->makeCacheKey();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function runAs(string $hostname = null, string $login = null, string $password = null): mixed
    {
        $hostname = $hostname ?? ($this->remoteServer->hostname ?? $this->remoteServer->ip);
        $login = $login ?? $this->remoteServer->username;
        $password = $password ?? $this->remoteServer->auth_string;

        $ssh = new SSHClient(
            targetOrUsername: $login,
            password: $password,
            hostname: $hostname,
            login: false,
        );

        try {
            $ssh->doSSHLogin();
            $temp = (new static($this->remoteServer, $ssh))->once();

            return $temp->run();

        } catch (Throwable $e) {
            $this->addCustomError($e->getMessage());
            return false;
        }
    }

    public function once(bool $once = true): self
    {
        $this->once = $once;
        return $this;
    }

    public function run(bool $fake = false): mixed
    {
        return $this->handle($fake);
    }

    /**
     * @throws Throwable
     */
    public function handle(bool $fake = false): mixed
    {
        $this->applyExternalParams();

        if ($this->remoteOS == RemoteOS::UNKNOWN) {
            throw new Exception("Невозможно выполнить команду на неизвестной ОС");
        }

        if ($this->shouldStop()) {
            $this->delete();
            return null;
        }

        try {

            $this->init();
            $this->started_at = Carbon::now();

            if (!$this->command) {
                throw new Exception("Не задана команда для запуска");
            }

            if (!$this->beforeExecuteOperations()) return false;

            $ret = retry(
                times: $this->retryCount,
                callback: function () use ($fake) {
                    if ($fake) return true;

                    $cmd = fn() => $this->execute($this->command);
                    $ttl = $ttl ?? Carbon::now()->addMilliseconds(1000);
                    return cache()->remember($this->makeCacheKey(), $ttl, $cmd);
                },
                sleepMilliseconds: $this->retryWait ?? 100
            );

            $this->executeAndSetResult($ret);
            $this->afterExecuteOperations();

            if ($this->isQueued()) {
                //$this->dump("Queued result for {$this->getSignature()}:", $this->result, $this->getErrors());
            }

            $this->ended_at = now();

            if ($this->shouldStop()) {
                return $this->result;
            }

            $rr = $this->requeue();

            if ($rr) {
                $this->dump("Задача повторно отправлена в очередь [uniqueID={$rr->uniqueId()}] [iteration={$rr->iteration}] [uniqueFor={$rr->uniqueFor}]");
            }

            return $this->result;

        } catch (Throwable $e) {
            if ($this->onFailureProc) {
                $p = $this->onFailureProc;
                $p($e);
            }

            throw $e;
        }
    }

    protected function shouldStop(): bool
    {
        // По серверу
        if (Redis::sismember("servers_disabled", $this->remoteServer->id)) {
            return true;
        }

        // По IP или имени
        if (Redis::sismember("servers_disabled_by_ip", $this->remoteServer->ip)) {
            return true;
        }

        return Redis::exists("global_monitoring_stopped")
            || Redis::sismember("command_disabled", get_class($this));
    }

    protected function beforeExecuteOperations(): bool
    {
        if ($this->executeBefore) {
            call_user_func($this->executeBefore, $this);
        }

        return $this->before();
    }

    protected function execute(string|array $command): bool|string
    {
        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        if ($command) {
            if ($this->isLocalServer() || ($this instanceof OnLocalServer && $this->onLocalServer ?? false)) {
                $ret = shell_exec($command);
            } else {
                $this->connectRemoteServerViaSSH();
                $this->sshClient->setTimeout($this->executeTimeout ?? 30);
                $ret = ($this->sshClient->runCommand($command, $this->errors, $this->exitCode));
            }

            if ($ret) {
                $c = class_basename($this);

                $ret = str($ret)->trim();
                if ($ret->contains('EXITCODE=')) {
                    $this->exitCode = $ret->after('EXITCODE=')->before("\n")->trim()->toInteger();
                    $ret = $ret->before('EXITCODE=')->trim();
                    $ret = $ret->whenEndsWith("\n;",
                        fn(Stringable $v) => $v->replaceLast("\n;", '')
                    )->trim();
                }

                if ($this->exitCode === 1) {
                    // внутренняя ошибка
                    $this->addCustomError("Выполнение команды $c прошло с ошибками, код 1, результат: $ret");
                }
                if ($this->exitCode === 2) {
                    // fatal error
                    $this->addCustomError("Выполнение команды $c неуспешно: код 2, результат: $ret");
                    $this->result = null;
                }

                if ($this->inputRegex && !preg_match($this->inputRegex, $ret->trim()->value())) {
                    $this->addCustomError("Ответ команды $c ($ret) не прошел проверку inputRegex");
                    return false;
                }
                return $ret;
            } else {
                return false;
            }
        }

        return false;
    }

    protected function isLocalServer(): bool
    {
        return $this->serverType === ServerType::SERVER_TYPE_LOCAL;
    }

    public function connectRemoteServerViaSSH(bool $force = false): bool
    {
        if ($this->once) {
            $this->sshClient ??= new SSHClient(
                targetOrUsername: $this->remoteServer->username,
                password: $this->remoteServer->auth_string,
                hostname: $this->remoteServer->hostname,
            );
        } else {

            if (($force || $this->serverType === ServerType::SERVER_TYPE_REMOTE) && !$this->sshClient) {
                $this->sshClient = new SSHClient(
                    targetOrUsername: config('panel.panel_user', $this->remoteServer->username),
                    password: config('panel.panel_password', $this->remoteServer->password),
                    hostname: $this->remoteServer->ip
                );
            }
        }

        return $this->sshClient instanceof SSHClient;
    }

    public function setTimeout(int $seconds): static
    {
        $this->executeTimeout = $seconds;
        return $this;
    }

    protected function executeAndSetResult(bool|string $ret): void
    {
        $c = class_basename($this);

        if ($ret || strlen($ret) > 0) {
            $this->result = $this->process($this->cleanupOutput($ret));

            if ($this->result !== null) {
                if ($this->outputRegex) {
                    if (!preg_match($this->outputRegex, $this->result)) {
                        $this->addCustomError("Ответ команды $c ($this->result) не прошел проверку outputRegex");
                        $this->result = null;

                        return;
                    }
                }

                if ($this->transformFunc) {
                    $this->result = call_user_func($this->transformFunc, $this->result);
                }
            }
        } else {
            $this->addCustomError("Команда $c вернула пустой ответ");
            $this->result = null;
        }
    }

    protected function requeue(): ?static
    {

        $job = clone $this->regenerate();

        if (($interval = $job->getInterval()) && !$job->cancelled) {

            $now = now()->toImmutable();

            if ($job->onTimeOverlap === TimeOverlapEnum::TIME_OVERLAP_DO_NOT_LAUNCH) {
                return null;
            }

            if ($job->onTimeOverlap === TimeOverlapEnum::TIME_OVERLAP_LAUNCH_WITH_TIME_DIFF) {

                $planned = $job->lastPlannedAt ?? $now;
                $nextPlannedAt = $planned->copy()->addSeconds($interval);
                // догоняем если сильно отстали
                $diff = $now->diffInSeconds($nextPlannedAt);

                if ($diff < 0) {
                    $missed = intdiv(abs($diff), $interval) + 1;
                    $nextPlannedAt->addSeconds($missed * $interval);
                }

                $delay = max(0, $now->diffInSeconds($nextPlannedAt, false));

                $job->lastPlannedAt = $nextPlannedAt;

            } else {
                // обычный режим
                $delay = $interval;
                $job->lastPlannedAt = $now->copy()->addSeconds($interval);
            }

            $delay = max(0, ceil($delay));
            if ($delay === 0) {
                $delay = 0.5;
            }

            $conn = $this->job?->getConnectionName();
            $this->dump("Задержка: $delay, queue: $job->queue, conn=$conn");

            if ($this->sync || $conn === 'sync') {
                for ($i = 0; $i < $delay; $i++) {
                    echo ".";
                    Sleep::for(1)->seconds();
                }
                echo "\n";
                $this->delete();
                dispatch_sync($job);

            }
            return $this->dispatchAndLock($job, $delay);

        } else return null;
    }

    public function regenerate(bool $incrementIteration = true): static
    {
        $job = clone $this;

        $job->job = null;
        $job->uuid = null;
        $job->result = null;
        $job->delay = null;
        unset($job->sshClient);//??

        return $job->incrementIteration($incrementIteration ? 1 : 0);
    }

    protected function incrementIteration(int $by = 1): static
    {
        $this->iteration += $by;
        $this->internalUniqueID = $this->generateUniqueId();
        return $this;
    }

    protected function dump(...$args): void
    {
        dump(...$args);
    }

    protected function dispatchAndLock($job, $delay, $lockFor = null)
    {
        $uit = $lockFor ?? $this->uniqueFor;
        $uid = $job->uniqueId();

        if (!Redis::exists("command_lock_{$uid}")) {
            dispatch(clone $job)->onQueue($job->queue)->delay($delay + $job->jitter);
            Redis::setex("command_lock_{$uid}", $uit, $uid);
            return $job;
        } else {
            $this->dump("Задача не будет запущена повторно, поскольку стоит lock на {$uid} на {$uit} сек.");
            return null;
        }
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function dispatch(bool $sync = false): PendingDispatch|int|null
    {
        return (!$sync) ? dispatch($this) : dispatch_sync($this);
    }

    public function batch(): mixed
    {
        $this->init();

        if (!$this->beforeExecuteOperations()) return false;

        return $this->command;
    }

    public function commandString(): string
    {
        if (!$this->command) $this->init();

        if (is_array($this->command)) {
            $c = implode(' ', $this->command);
        } else {
            $c = $this->command;
        }

        return trim($c);
    }

    public function setSsh(?SSHClient $ssh): self
    {
        $this->sshClient = $ssh;
        return $this;
    }

    public function hasSsh(): bool
    {
        return $this->sshClient instanceof SSHClient;
    }

    public function retry(int $count, int $waitMilliseconds = 100): static
    {
        $this->retryCount = $count;
        $this->retryWait = $waitMilliseconds;
        return $this;
    }

    public function getTtl(): int
    {
        if (!$this->ttl) return 0;
        if (is_int($this->ttl)) {
            return $this->ttl;
        }
        if ($this->ttl instanceof CarbonInterface) {
            return now()->diffInSeconds($this->ttl);
        }
        return -1;
    }

    public function fromCache(bool $withCacheKey = false): array
    {
        $key = $this->makeCacheKey();
        $value = cache()->get($key);

        if ($withCacheKey) {
            return [$key => $value];
        }
        return [0 => $value];
    }

    public function stopOnFailure(bool $stop = true): static
    {
        $this->stopOnFailure = $stop;
        return $this;
    }

    public function shouldStopOnFailure(): bool
    {
        return $this->stopOnFailure;
    }

    protected function getIteration(): int
    {
        return $this->iteration;
    }

    protected function updateServerConfig(int $serverId, string $param, mixed $value, ...$params): void
    {
        if (is_callable($value)) {
            $value = $value($params);
        }
        RemoteServer::find($serverId)?->setConfig($param, $value, $params['save'] ?? true);
    }

}
