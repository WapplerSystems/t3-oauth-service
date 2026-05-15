<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;

class Connection extends AbstractDomainObject
{

    public const string CONNECTED = 'connected';
    public const string DISCONNECTED = 'disconnected';
    public const string ERROR = 'error';

    protected ?Client $client = null;
    protected ?string $accessToken = null;
    protected ?\DateTimeImmutable $accessTokenExpiresAt = null;
    protected ?string $refreshToken = null;
    protected ?\DateTimeImmutable $refreshTokenExpiresAt = null;
    protected ?string $scope = null;
    protected ?string $resourceOwnerId = null;
    protected ?string $status = null;
    protected ?string $stateHash = null;
    protected ?int $stateCreatedAt = null;
    protected string $lastErrorMessage = '';
    protected string $lastErrorCode = '';
    protected string $tokenType = '';
    protected ?\DateTimeImmutable $lastRefreshAt = null;
    protected ?\DateTimeImmutable $lastCheckAt = null;
    protected ?string $codeVerifier = null;
    protected string $metadata = '';



    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function getAccessTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessTokenExpiresAt;
    }

    public function setAccessTokenExpiresAt(?\DateTimeImmutable $accessTokenExpiresAt): void
    {
        $this->accessTokenExpiresAt = $accessTokenExpiresAt;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getRefreshTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->refreshTokenExpiresAt;
    }

    public function setRefreshTokenExpiresAt(?\DateTimeImmutable $refreshTokenExpiresAt): void
    {
        $this->refreshTokenExpiresAt = $refreshTokenExpiresAt;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): void
    {
        $this->scope = $scope;
    }

    public function getResourceOwnerId(): ?string
    {
        return $this->resourceOwnerId;
    }

    public function setResourceOwnerId(?string $resourceOwnerId): void
    {
        $this->resourceOwnerId = $resourceOwnerId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getStateHash(): ?string
    {
        return $this->stateHash;
    }

    public function setStateHash(?string $stateHash): void
    {
        $this->stateHash = $stateHash;
    }

    public function getStateCreatedAt(): ?int
    {
        return $this->stateCreatedAt;
    }

    public function setStateCreatedAt(?int $stateCreatedAt): void
    {
        $this->stateCreatedAt = $stateCreatedAt;
    }

    public function getLastErrorCode(): string
    {
        return $this->lastErrorCode;
    }

    public function setLastErrorCode(string $lastErrorCode): void
    {
        $this->lastErrorCode = $lastErrorCode;
    }

    public function getLastErrorMessage(): string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    public function setTokenType(string $tokenType): void
    {
        $this->tokenType = $tokenType;
    }

    public function getLastRefreshAt(): ?\DateTimeImmutable
    {
        return $this->lastRefreshAt;
    }

    public function setLastRefreshAt(?\DateTimeImmutable $lastRefreshAt): void
    {
        $this->lastRefreshAt = $lastRefreshAt;
    }

    public function getLastCheckAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckAt;
    }

    public function setLastCheckAt(?\DateTimeImmutable $lastCheckAt): void
    {
        $this->lastCheckAt = $lastCheckAt;
    }

    public function getCodeVerifier(): ?string
    {
        return $this->codeVerifier;
    }

    public function setCodeVerifier(?string $codeVerifier): void
    {
        $this->codeVerifier = $codeVerifier;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    public function setMetadata(string $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * Returns the metadata as decoded array, or empty array if not set/invalid.
     */
    public function getMetadataArray(): array
    {
        if ($this->metadata === '') {
            return [];
        }
        $data = json_decode($this->metadata, true);
        return is_array($data) ? $data : [];
    }

    public function setMetadataFromArray(array $metadata): void
    {
        $this->metadata = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

}
