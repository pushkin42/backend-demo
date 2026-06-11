<?php

namespace App\Services\User;

use App\Enums\PriceType;
use App\Enums\UserSessionType;
use App\Exceptions\User\FailedToSendTwoFactorCodeException;
use App\Models\User;
use App\Notifications\SendUserActivationCodeNotification;
use App\Services\BaseAppService;
use App\Services\Catalog\CatalogServiceInterface;
use App\Services\Global\Cache\CacheServiceInterface;
use App\Services\Network\NetworkServiceInterface;
use Cartalyst\Sentinel\Sentinel;
use Cartalyst\Sentinel\Users\UserInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jenssegers\Agent\Agent;
use Symfony\Component\HttpFoundation\IpUtils;
use App\Contracts\UserOwnObjectInterface;

class UserIdentificationService extends BaseAppService implements UserIdentificationServiceInterface
{
    const string INTERNAL_SESSION_KEY = 'session_id';

    public function __construct(
        protected readonly CacheServiceInterface $cache,
        protected readonly Sentinel              $sentinel,
        protected readonly Agent                 $agent,
    )
    {
    }

    public function getHeaderStats(): array
    {
        $u = $this->userOrSessionId();
        $cs = app(CatalogServiceInterface::class);

        $cartStats = $cs->getCartStatsFor(for: $u, cartId: $cs->getCartIdFor($u));
        $cartTotal = data_get($cartStats, 'total', 0);
        $cartCount = data_get($cartStats, 'quantity', 0);

        $favCount = $cs->getFavoritesCountFor($u);

        return [
            'stats' => [
                'fav_count' => $favCount,
            ],
            'cart' => [
                'total' => $cartTotal,
                'count' => $cartCount,
                'mode' => app(CatalogServiceInterface::class)->getCartModeFor()
            ],
            'session' => [
                'laravel_id' => session()->id(),
                'internal_id' => $this->getSessionId(),
            ]
        ];
    }

    public function userOrSessionId(): UserInterface|string|null
    {
        return $this->getUser() ?? $this->getSessionId();
    }

    public function getUser(bool $check = true): User|UserInterface|null
    {
        return $this->sentinel->getUser($check);
    }

    // Laravel session id может регенерироваться, поэтому для корзины/избранного/анонимной активности используется отдельный стабильный внутренний идентификатор.
    public function getSessionId(UserSessionType $type = UserSessionType::SESSION_TYPE_INTERNAL): ?string
    {
        $this->ensureIsSessionStarted();

        $sessionId = match ($type) {
            UserSessionType::SESSION_TYPE_INTERNAL => session(self::INTERNAL_SESSION_KEY),
            UserSessionType::SESSION_TYPE_LARAVEL => session()->getId(),
            UserSessionType::SESSION_TYPE_PHP => session_id(),
        } ?? null;

        if ($sessionId === null) {
            return $this->setSessionId();
        }

        return $sessionId;

    }

    private function ensureIsSessionStarted(): void
    {
        if (!session()->isStarted()) {
            session()->start();
        }
    }

    public function setSessionId(?string $id = null, UserSessionType $type = UserSessionType::SESSION_TYPE_INTERNAL): string
    {
        $this->ensureIsSessionStarted();

        if ($id === null) {
            $ua = $this->agent->getUserAgent();
            $ip = $this->ip();
            $ts = microtime(true);
            $id = hash('sha256', "$ua-$ip-$ts");
        }
        $id = str($id)->lower()->substr(0, 40)->trim()->value();

        if ($type === UserSessionType::SESSION_TYPE_LARAVEL) {
            session()->setId($id);
            return $id;
        }
        if ($type === UserSessionType::SESSION_TYPE_INTERNAL) {
            session()->put(self::INTERNAL_SESSION_KEY, $id);
            return $id;
        }
        if ($type === UserSessionType::SESSION_TYPE_PHP) {
            session_id($id);
            return $id;
        }

        throw new InvalidArgumentException("Ошибка назначения ID сессии");
    }

    public function ip(): string
    {
        $n = app(NetworkServiceInterface::class);
        $ip = $n->ip();
        if (IpUtils::isPrivateIp($ip)) {
            return config('access.ip.server_ip');
        }

        return $ip;
    }

    public function loginByCredentials(string $username, string $password, bool $remember = false): User|UserInterface|bool|null
    {
        $username = trim($username);
        return $this->sentinel->authenticate(['login' => $username, 'password' => $password], $remember);
    }

    public function makeActivationCodeFor(UserInterface|User|string $user, bool $send = false): string|bool
    {
        $user = $this->resolveUser($user);
        if (!$user) return false;

        $code = $this->sentinel->getActivationRepository()->create($user)?->getCode();

        if (!$code) return false;

        if ($send) {
            session()->flash('need_activation');
            session()->flash('activation_code', $code);
            $user->notify(new SendUserActivationCodeNotification($user, $code));
        }

        return $code;
    }

    private function resolveUser(mixed $user, bool $check = false): ?UserInterface
    {
        if ($user === null) {
            return $this->getUser($check);
        }

        if ($user instanceof UserInterface) {
            return $user;
        }

        if (is_int($user) || (ctype_digit($user))) {
            return User::query()->where('id', $user)->first();
        }

        if (is_string($user)) {
            // поиск по логину/емейлу
            return User::query()->where('login', $user)->orWhere('data->phone', $user)->first();
        }

        throw new InvalidArgumentException("Переданный параметр user не удалось распознать");
    }

    public function logout(UserInterface|User|string|null $user = null): bool
    {
        if (!$this->loggedIn()) return true;
        return $this->sentinel->logout($user);
    }

    public function loggedIn(): bool
    {
        return $this->getUser() instanceof UserInterface;
    }

    public function findUserByLogin(string $login): User|UserInterface|null
    {
        return $this->resolveUser($login);
    }

    public function getUserProperties(UserInterface $user = null): ?array
    {
        /**
         * @var User $user
         */
        $user = $this->resolveUser($user) ?? $this->getUser(true);
        if (!$user) return [
            'id' => null,
            'login' => null,
            'is_admin' => false,
            'permissions' => null,
        ];

        return array_merge(
            $user->only(['id', 'login']),
            [
                'is_admin' => $user->isAdmin(),
                'permissions' => $user->getPermissionsInstance()->getPermissions(),
                'fio' => $user->fio,
            ]
        );
    }

    public function createUser(array $data, bool $activate = false): bool|UserInterface
    {
        $mainFields = ['login', 'password', 'password_again', 'first_name', 'last_name'];

        data_forget($data, 'password_again');
        data_set($data, "data",
            Arr::except($data, $mainFields),
        );

        foreach (array_keys($data['data']) as $field) {
            data_forget($data, $field);
        }

        $credentials = Arr::only($data, ['login', 'password']);
        $data = data_get($data, 'data');

        if ($activate) {
            $newUser = $this->sentinel->registerAndActivate($credentials);
        } else {
            $newUser = $this->sentinel->register($credentials);
        }
        if (!$newUser instanceof UserInterface) {
            return false;
        }
        $newUser->data = $data;

        return ($newUser->save()) ? $newUser : false;
    }

    public function activateUserAndLogin(UserInterface|User|string $user, string $code = null): bool
    {
        $user = $this->resolveUser($user);
        if (!$user) return false;

        if ($this->activateUser($user, $code)) {
            return ($this->loginByUser($user));
        }

        return false;
    }

    public function activateUser(UserInterface|User|string $user, string $code = null): bool
    {
        $user = $this->resolveUser($user);
        if (!$user) return false;

        return $this->sentinel->getActivationRepository()->complete($user, $code);
    }

    public function loginByUser(UserInterface|User $user, bool $remember = false): bool
    {
        if ($this->loggedIn()) return true;
        return $this->sentinel->login($user, $remember) instanceof UserInterface;
    }

    public function checkOrMake2FACode($code = null): bool|string
    {
        if (!$this->loggedIn()) return false;

        $ttl = session()->get("2fa:ttl");

        if ($at = session()->has("2fa:checked_at")) {
            // код уже был проверен ранее
            return true;
        }

        if (!$ttl || (time() > $ttl)) {
            session()->forget("2fa:code");
            session()->forget("2fa:ttl");
            session()->forget("2fa:checked_at");
        }

        $currentCode = session()->get("2fa:code");

        if (!$currentCode) {
            // создаем новый код
            $currentCode = Str::randomStringUsing('1234567890', config('access.2fa.length', 8));
            session()->put("2fa:code", $currentCode);
            session()->put("2fa:ttl", time() + intval(config('access.2fa.ttl', 60)));
        }

        if ($code) {

            $checked = ($currentCode && $currentCode === $code && $ttl && $ttl >= time());
            if ($checked) {
                session()->put("2fa:checked_at", time());
                session()->forget("2fa:code");
                session()->forget("2fa:ttl");
            } else {
                session()->forget("2fa:checked_at");
            }
            return $checked;
        }


        return $currentCode;
    }

    public function cleanupSession(): bool
    {
        session()->forget('2fa:checked_at');
        session()->forget("2fa:code");
        session()->forget("2fa:ttl");

        session()->regenerate(true);

        return true;
    }

    /**
     * @throws FailedToSendTwoFactorCodeException
     */
    public function send2FACodeTo(UserInterface|User|null $user, string $code, string $target = 'Telegram'): bool
    {
        $user = $this->resolveUser($user);
        if (!$user) return false;

        $chatId = $user->chat_id;
        if (!$chatId) {
            throw new FailedToSendTwoFactorCodeException("У пользователя не настроена связка с $target");
        }
        //TODO: код отправки в ТГ
        return false;
    }

    public function findUser(int $id): UserInterface
    {
        return $this->sentinel->getUserRepository()->findById($id);
    }

    public function checkOwn(UserOwnObjectInterface $object, mixed $user = null): bool
    {
        if ($user === null) $user = $this->userOrSessionId();

        $user = ($object instanceof UserInterface) ? $object->getUserId() : $user;
        $type = ($user instanceof UserInterface) ? 'user_id' : 'session_id';

        $src = data_get($object, $type);

        if (!$src) return false;


        return $src == $this->userOrSessionIdParam(true);
    }

    public function userOrSessionIdParam($stringable = false, bool|string $mode = false): array|string
    {
        $p = $this->userOrSessionId();
        if (is_string($mode)) {
            $mode = str($mode)->lower()->trim()->value();
        }

        if ($mode === 'and') {
            return [
                'user_id' => ($p instanceof UserInterface) ? $p->getUserId() : null,
                'session_id' => (is_string($p)) ? $p : null,
            ];
        }

        if ($stringable) {
            return ($p instanceof UserInterface) ? $p->getUserId() : $p;
        } else {
            return ($p instanceof UserInterface) ? ['user_id' => $p->getUserId()] : ['session_id' => $p];
        }

    }

    public function getPriceTypeFor(mixed $for = null): PriceType
    {
        $for = $this->resolveUser($for);

        if ($for === null) return PriceType::PRICE_TYPE_ROZN;

        if ($for instanceof UserInterface) {
            return $for->getData('price_type', PriceType::PRICE_TYPE_OPT) ?? PriceType::PRICE_TYPE_ROZN;
        }

        return PriceType::PRICE_TYPE_ROZN;
    }

    public function registerUserByEmail(string $email, array $data = [], &$password = null): bool|UserInterface
    {
        $password = Str::random(config('access.passwords.length', 8));
        return $this->registerUser(array_merge(['login' => $email, 'password' => $password], $data));

    }

    public function registerUser(...$params): null|bool|UserInterface
    {
        $data = head([...$params]);
        $login = data_get($data, 'login');
        $password = data_get($data, 'password');
        $activate = data_get($data, 'activate', false);

        if (!$login || !$password) {
            return null;
        }

        if ($activate) {
            $ret = $this->sentinel->registerAndActivate(['login' => $login, 'password' => $password]);
        } else {
            $ret = $this->sentinel->register(['login' => $login, 'password' => $password]);
        }

        return $ret;
    }

    public function getThumbmark(): ?string
    {
        return session('thumbmark');
    }

    public function makeSessionId(...$keys): string
    {
        $keys = Arr::flatten($keys);
        $key = str(implode('_', $keys))->trim()->append('_');
        return str(sha1($key))->substr(0, 40)->trim()->value();
    }
}
