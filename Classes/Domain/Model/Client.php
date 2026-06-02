<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Client extends AbstractDomainObject {

    protected ?string $provider = null;
    protected ?string $accessTokenUrl = null;
    protected ?string $clientId = null;
    protected ?string $clientSecret = null;
    protected ?string $tokenMethod = null;
    protected bool $isActive = false;
    protected string $scopes;
    protected ?string $notifyEmail = null;
    protected ?string $metadata = null;


    /**
     * @var ObjectStorage<Connection>
     */
    protected ObjectStorage $connections;


    public function __construct() {
        $this->connections = new ObjectStorage();
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): void
    {
        $this->clientSecret = $clientSecret;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getScopes(): string
    {
        return $this->scopes;
    }

    public function setScopes(string $scopes): void
    {
        $this->scopes = $scopes;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): void
    {
        $this->notifyEmail = $notifyEmail;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * Returns a specific value from the metadata JSON, with dot-notation support.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        $raw = $this->metadata ?? '';
        if ($raw === '') {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }

        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function getConnections(): ObjectStorage
    {
        return $this->connections;
    }

    public function setConnections(ObjectStorage $connections): void
    {
        $this->connections = $connections;
    }


}
