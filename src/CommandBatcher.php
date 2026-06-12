<?php

namespace App\Collector\Commands;

use App\Models\Global\RemoteServer;
use App\Services\SSHClient;
use Arr;
use Exception;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class CommandBatcher
{
    protected array $commands = [];
    protected array $text = [];
    protected array $ttl = [];
    protected array $commandObjects = [];

    protected array $results = [];
    protected array $errors = [];

    protected $started_at = null, $ended_at = null;

    protected array $localCommands = [];

    protected int $retry = 1;
    protected int $sshTimeoutSeconds = 60;

    protected $onFailure = null;
    protected $onSuccess = null;

    public function __construct(
        protected RemoteServer $server,
        protected ?SSHClient   $ssh = null,
    )
    {
    }

    public function retry(int $retry): self
    {
        $this->retry = $retry;
        return $this;
    }

    public function onFailure(callable $callback): self
    {
        $this->onFailure = new SerializableClosure($callback);
        return $this;
    }

    public function onSuccess(callable $callback): self
    {
        $this->onSuccess = new SerializableClosure($callback);
        return $this;
    }

    public function add(BaseRemoteCommand|string|array $command): self
    {
        if (is_array($command)) {
            $this->commands = array_merge($this->commands, $command);
        } else {
            $this->commands[] = $command;
        }
        return $this;
    }

    public function run(): ?array
    {

        $ret = null;
        $parsed = [];
        $fn = null;
        $this->started_at = now();

        try {
            try {
                if (!$this->ssh && !$this->server?->isLocal()) {
                    $this->ssh = new SSHClient($this->server, useConfiguredCredentials: true)
                    ?->setTimeout($this->sshTimeoutSeconds);

                }
            } catch (Throwable $e) {
                $this->handleFailure($e, $this->server, $this->ssh);
            }


            foreach ($this->commands as $i => $command) {
                /**
                 * @var BaseRemoteCommand $command
                 */

                if (is_string($command)) {
                    $command = new $command($this->server, $this->ssh);
                }

                if (!$command->hasServer()) {
                    $command = $command->setServer($this->server);
                }
                if (!$command->hasSsh() && !$this->server?->isLocal()) {
                    $command = $command->setSsh($this->ssh);
                }

                $shouldRunLocal = $command instanceof OnLocalServer &&
                property_exists($command, 'onLocalServer') &&
                $command->onLocalServer;

                if ($shouldRunLocal) {
                    $this->localCommands[$command->getSignature()] = $command;
                }

                $this->commandObjects[] = $command;

                $r = $command->batch();

                $ttl = $command->getTtl();

                $this->ttl[$command->getSignature()] = $ttl;
                $ck = 0;
                $value = null;


                $signature = $command->getSignature();
                if (!$shouldRunLocal) {
                    Arr::map($command->fromCache(true), function ($v, $k) use (&$ck, &$value) {
                        $ck = $k;
                        $value = $v;
                    });

                    if ($ttl && $ck && $value) {
                        $vv = base64_encode($value);
                        $this->text[$signature] = "echo 'cached:$ck:$vv'; ";
                    } else {
                        if ($r) $this->text[$signature] = $r;
                    }
                } else {
                    $this->text[$signature] = "echo '~~~LOCAL~~~'";
                }
            }

            $script = collect($this->text)
            ->implode(function ($s) {
                $currentCommand = str(array_search($s, $this->text))->camel();
                return "echo '__START__{$currentCommand}__'\n$s\necho '__END__{$currentCommand}__'\n\n";
            });

            $script = "set +e\n$script";

            $hash = md5($script);
            $fn = "/tmp/{$hash}.sh";

            if ($this->server->isLocal()) {
                $p = $fn;
                @unlink($fn);
                file_put_contents($fn, $script);
                chmod($fn, 0700);
            } else {
                $p = $this->ssh?->uploadFile($fn, $script, 0770);
            }

            $ret = retry(
                times: $this->retry,
                callback: fn() => ($this->server->isLocal() ? shell_exec("bash $fn") : $this->ssh->runCommand("bash $p", $this->errors)),
                         sleepMilliseconds: 1000,
                         when: function ($e) {
                             $m = str($e->getMessage());
                             return $m->lower()->contains(['timeout', 'connection reset', 'broken pipe']);
                         }
            );

            if (!str($ret)->containsAll(['__START__', '__END__'])) {
                throw new Exception("Не удалось выполнить скрипт на сервере: " . $ret);
            }

            $pattern = '/__START__(?<command>.*?)__\n(?<value>.*?)\n__END__\k<command>__/s';
            preg_match_all($pattern, $ret, $matches, PREG_SET_ORDER);
            $matches = Arr::map($matches, fn($v) => Arr::only($v, ['command', 'value']));

            $matches = array_combine(array_values($this->commands), $matches);

            foreach ($matches as $k => $v) {
                $i = array_search($k, $this->commands);
                $cmd = $this->commandObjects[$i];
                $key = ($cmd->getSignature()) ?? $k;
                $value = trim(data_get($v, 'value'));

                if (empty($value) && $cmd->shouldStopOnFailure() && (!$cmd->onLocalServer())) {
                    throw new Exception("Батч остановлен - задача {$key} вернула неожиданый результат в процессе выполнения скрипта на сервере");
                }


                if ($value && ($ttl = $cmd->getTtl())) {
                    $cacheKey = $cmd->getCacheKey();

                    if (str($value)->startsWith('cached:')) {
                        [$cached, $ck, $value] = explode(':', $value);
                        if ($ck == $cacheKey) {
                            $value = base64_decode($value);
                        }
                    } else {
                        if ($cacheKey) cache()->remember($cacheKey, $ttl, fn() => $value);
                    }
                }

                if ($value !== '~~~LOCAL~~~') {
                    $result = $k::batchProcess(server: $this->server, command: $this->commandObjects[$i], input: $value);

                    if ($result === null && $cmd->shouldStopOnFailure()) {
                        throw new Exception("Батч остановлен - задача {$key} вернула неожиданый результат при разборе");
                    }

                    $parsed[$key] = $result;
                }
            }

            // обработка локальных команд
            foreach ($this->localCommands as $key => &$command) {
                $parsed[$key] = $command->batchProcess(server: $this->server, command: $command, input: shell_exec($command->batch()));
            }

            $this->ended_at = now();
            $this->handleSuccess($this->server, $this->ssh, $parsed, $this->ended_at?->diffInMilliseconds($this->started_at, true));


        } catch (Throwable $e) {
            $this->handleFailure($e, $this->server, $this->ssh, $ret);
        } finally {

            if (isset($fn)) {
                if ($this->server->isLocal()) {
                    @unlink($fn);
                } else {
                    $this->ssh?->deleteFile($fn);
                }
            }


        }
        if (!$this->ended_at) $this->ended_at = now();

        $parsed['estimates'] = [
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'time' => $this->getBatchTotalTime()
        ];

        return $parsed;

    }

    public function setTimeout(int $timeout): static
    {
        $this->sshTimeoutSeconds = $timeout;
        return $this;
    }

    protected function handleFailure(Throwable|Exception $e, RemoteServer $server, ?SSHClient $ssh, ...$data): void
    {
        if ($this->onFailure && is_callable($this->onFailure)) {
            call_user_func($this->onFailure, $e, $server, $ssh, ...$data);
        } else {
            throw $e;
        }
    }

    protected function handleSuccess(RemoteServer $server, ?SSHClient $ssh, ...$data)
    {
        if ($this->onSuccess && is_callable($this->onSuccess)) {
            call_user_func($this->onSuccess, $server, $ssh, ...$data);
        }
    }

    public function getBatchTotalTime(): ?int
    {
        return ($this->ended_at ?? now())->diffInMilliseconds($this->started_at, true);
    }
}
