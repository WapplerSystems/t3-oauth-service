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

Other extensions register OAuth providers via `Services.php`:

```php
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->addCompilerPass(
        new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void {
                $registry = $container->findDefinition(ProviderRegistryInterface::class);
                $registry->addMethodCall('register', [
                    new Definition(ProviderDefinition::class, [
                        'my_provider',                               // identifier
                        'My Provider',                               // title
                        'generic_oauth2',                            // type
                        'https://provider.example/oauth/authorize',  // authorizationUrl
                        'https://provider.example/oauth/token',      // tokenUrl
                        ['read', 'write'],                           // defaultScopes
                    ]),
                ]);
            }
        }
    );
};
```

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
