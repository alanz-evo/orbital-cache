<?php

namespace AlanzEvo\OrbitalCache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Exception;
use Throwable;

class OrbitalCache
{
    protected $cache = null;
    protected $store = null;

    public static function __callStatic($name, $arguments)
    {
        return app(static::class)->$name(...$arguments);
    }

    public static function store(string $store = null)
    {
        return app(static::class, ['store' => $store]);
    }

    public function __construct(string $store = null)
    {
        $this->store = $store;
    }

    public function __call($name, $arguments)
    {
        $allowMethods = [
            'add',
            'forever',
            'forget',
            'has',
            'missing',
            'put',
            'decrement',
            'increment',
            'get',
            'pull',
            'remember',
            'rememberForever',
            'sear',
        ];

        if (!in_array($name, $allowMethods)) {
            throw new Exception("Method `{$name}` not found in " . static::class . ".");
        }

        $orbit = $this->getOrbit();
        $arguments = array_values($arguments);
        $arguments[0] = 'orbital-cache:' . $orbit . ':data:' . $arguments[0];

        return $this->$name(...$arguments);
    }

    public function operate(Closure $callback)
    {
        $cache = $this->getCache();
        $orbit = $this->getOrbit();
        try { 
            $this->newOperating($orbit);
            $callback($cache);
        } finally {
            $this->finishOperating($orbit);
        }
    }

    public function switch(int $waitingSec = 10)
    {        
        try {
            $cache = $this->getCache();
            $lock = $cache->lock('orbital-cache:switching', $waitingSec * 2);
            $lock->block(0);
            $orbit = $this->getOrbit();
            $waitingMs = $waitingSec * 1000000;
            while ($this->isOperating($orbit) && $waitingMs > 0) {
                usleep(100000);
                $waitingMs -= 100000;
            }
            $this->setOrbit($orbit == 0 ? 1 : 0);
        } catch (Throwable $th) {
            optional($lock)->release();
            throw $th;
        }
    }

    protected function newOperating($orbit)
    {
        $cache = $this->getCache();
        return $cache->increment('orbital-cache:' . $orbit . ':operating');
    }

    protected function finishOperating($orbit)
    {
        $cache = $this->getCache();
        return $cache->decrement('orbital-cache:' . $orbit . ':operating');
    }

    protected function isOperating($orbit)
    {
        $cache = $this->getCache();
        $operating = $cache->get('orbital-cache:' . $orbit . ':operating');

        return !is_null($operating) && $operating > 0;
    }

    protected function getCache()
    {
        if (is_null($this->cache)) {
            $this->cache = Cache::store($this->store);
        }

        return $this->cache;
    }

    protected function getOrbit()
    {
        $cache = $this->getCache();
        
        $orbit = $cache->get('orbital-cache:current-orbit', null);

        if (is_null($orbit)) {
            $this->setOrbit(0);
            $orbit = 0;
        }

        return $orbit;
    }

    protected function setOrbit(int $orbit)
    {
        $cache = $this->getCache();
        $cache->put('orbital-cache:current-orbit', $orbit);
    }
}