<?php

namespace Rikudou\Unleash\Configuration;

use Psr\SimpleCache\CacheInterface;

final class UnleashConfiguration
{
    private ?CacheInterface $cache = null;

    private int $ttl = 30;

    public function __construct(
        private string $url,
        private string $appName,
        private string $instanceId
    ) {
        if (substr($this->url, -1) !== '/') {
            $this->url .= '/';
        }
    }

    public function getCache(): ?CacheInterface
    {
        return $this->cache;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function setCache(?CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function setAppName(string $appName): self
    {
        $this->appName = $appName;

        return $this;
    }

    public function setInstanceId(string $instanceId): self
    {
        $this->instanceId = $instanceId;

        return $this;
    }
}