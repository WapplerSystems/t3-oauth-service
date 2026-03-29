<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use WapplerSystems\OauthService\Provider\ProviderDefinition;

class Client extends AbstractDomainObject {

    protected ?string $title = null;
    protected ?string $provider = null;
    protected ?string $accessTokenUrl = null;
    protected ?string $clientId = null;
    protected ?string $clientSecret = null;
    protected ?string $tokenMethod = null;
    protected ?string $meta = null;
    protected bool $isActive = false;
    protected string $scopes;
    protected ?string $notifyEmail = null;


    /**
     * @var ObjectStorage<Connection>
     */
    protected ObjectStorage $connections;


    public function __construct() {
        $this->connections = new ObjectStorage();
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
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

    public function getMeta(): ?string
    {
        return $this->meta;
    }

    public function getMetaDecoded(): array
    {
        if ($this->meta === null) {
            return [];
        }
        $data = json_decode($this->meta, true);
        return is_array($data) ? $data : [];
    }

    public function setMeta(?string $meta): void
    {
        $this->meta = $meta;
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

    public function getConnections(): ObjectStorage
    {
        return $this->connections;
    }

    public function setConnections(ObjectStorage $connections): void
    {
        $this->connections = $connections;
    }


}
