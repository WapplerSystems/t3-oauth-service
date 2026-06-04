# OAuth Service for TYPO3

Central OAuth2 client and token management for TYPO3 v14.

## Features

- Manage multiple OAuth2 clients and connections via a backend module
- **Authorization Code Flow with PKCE** (RFC 7636, OAuth 2.1 compliant)
- Encrypted token storage (libsodium, derived from TYPO3 encryption key)
- Automatic token refresh via console command / scheduler
- Expiry monitoring with configurable email warnings
- Extensible provider system — register custom OAuth providers from any extension

## Requirements

- TYPO3 v14
- PHP 8.2+
- `ext-sodium`

## Installation

```bash
composer require wapplersystems/oauth-service
```

Then update the database schema:

```bash
typo3 extension:setup
```

## Configuration

Extension settings under **Admin Tools > Settings > Extension Configuration > oauth_service**:

| Setting | Default | Description |
|---------|---------|-------------|
| `thresholdSeconds` | `300` | Refresh tokens expiring within this many seconds |
| `debounceMinutes` | `360` | Min. gap between failure notifications per connection |
| `warningEmail` | | Comma-separated emails for expiry warnings |
| `warningThresholdDays` | `7,3,1` | Days before expiry to send warnings |
| `debounceHours` | `20` | Min. gap between warning emails per connection |

## Usage

### Backend Module

The module is available at **System > OAuth Services** (admin only). It lists all configured clients with their connections, token status, and expiry info.

### Registering a Provider

Providers are registered via a DI tag — `ProviderRegistry`'s constructor consumes a tagged iterator and populates itself at container build time. This makes registered providers visible in any bootstrap mode, **including the TYPO3 Install Tool's failsafe boot** (where extension `ext_localconf.php` files are NOT executed). Use this for any code path the Install Tool might hit, such as the "Test Mail Setup" form when a mail transport depends on OAuth tokens.

**Recommended: `Configuration/Services.yaml`**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  # … your other services …

  my_extension.provider_definition:
    class: WapplerSystems\OauthService\Provider\ProviderDefinition
    autowire: false
    arguments:
      $identifier: 'my_provider'
      $title: 'My Provider'
      $type: 'generic_oauth2'
      $authorizationUrl: 'https://provider.example/oauth/authorize'
      $tokenUrl: 'https://provider.example/oauth/token'
      $defaultScopes: ['read', 'write']
    tags:
      - 'oauth_service.provider_definition'
```

**Equivalent: `Configuration/Services.php`**

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\OauthService\Provider\ProviderDefinition;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Vendor\\MyExtension\\', __DIR__ . '/../Classes/*');

    $services->set('my_extension.provider_definition', ProviderDefinition::class)
        ->autowire(false)
        ->args([
            '$identifier' => 'my_provider',
            '$title' => 'My Provider',
            '$type' => 'generic_oauth2',
            '$authorizationUrl' => 'https://provider.example/oauth/authorize',
            '$tokenUrl' => 'https://provider.example/oauth/token',
            '$defaultScopes' => ['read', 'write'],
        ])
        ->tag('oauth_service.provider_definition');
};
```

#### Legacy: imperative registration

Older releases registered providers via `ext_localconf.php`:

```php
$registry = GeneralUtility::makeInstance(ProviderRegistryInterface::class);
$registry->register(new ProviderDefinition(
    identifier: 'my_provider', title: 'My Provider', type: 'generic_oauth2', /* … */
));
```

This still works — `register()` is idempotent by identifier, so existing code is not broken by the DI-tag mechanism. However, providers registered only this way are **invisible to the Install Tool's failsafe bootstrap**, so prefer the DI-tag pattern above.

### Retrieving Tokens

Use `OAuthClientService` to get decrypted access tokens:

```php
use WapplerSystems\OauthService\Service\OAuthClientService;

class MyService
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
    ) {}

    public function callApi(): void
    {
        $connection = $this->oAuthClientService->getActiveConnectionByProvider('my_provider');
        $accessToken = $connection['access_token'];
        // use $accessToken for API calls
    }
}
```

### Console Commands

**Refresh expiring tokens** (recommended: every 5 minutes via scheduler):

```bash
typo3 oauth-service:refresh-tokens
typo3 oauth-service:refresh-tokens --uid 3 --force
typo3 oauth-service:refresh-tokens --threshold 600
```

**Monitor connections** (recommended: daily):

```bash
typo3 oauth-service:monitor-connections
```

## Security

- All tokens and client secrets are encrypted with libsodium (XSalsa20-Poly1305)
- CSRF protection via state parameter with SHA-256 hash and 10-minute timeout
- PKCE (S256) on every authorization code flow
- Token fields are read-only in the backend UI

## License

GPL-2.0-or-later
