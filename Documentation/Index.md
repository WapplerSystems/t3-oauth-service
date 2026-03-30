# OAuth Service für TYPO3

**Extension-Key:** `oauth_service`
**Paket:** `wapplersystems/oauth-service`
**TYPO3-Kompatibilität:** 13.4.x
**Voraussetzungen:** PHP-Extension `ext-sodium`

---

## Inhaltsverzeichnis

1. [Überblick](#überblick)
2. [Installation](#installation)
3. [Konfiguration](#konfiguration)
4. [Backend-Modul](#backend-modul)
5. [Clients anlegen](#clients-anlegen)
6. [Verbindungen verwalten](#verbindungen-verwalten)
7. [Konsolen-Befehle](#konsolen-befehle)
8. [Provider registrieren](#provider-registrieren)
9. [Integration in andere Extensions](#integration-in-andere-extensions)

---

## Überblick

Die Extension stellt eine zentrale OAuth2-Infrastruktur für TYPO3 bereit. Sie verwaltet OAuth-Clients und deren aktive Verbindungen (Access-/Refresh-Tokens) und bietet anderen Extensions einen einheitlichen Zugriff auf gespeicherte Zugangsdaten.

**Kernfunktionen:**

- Verwaltung mehrerer OAuth2-Clients und -Verbindungen im Backend
- Verschlüsselte Speicherung aller Tokens (libsodium, TYPO3-Encryption-Key)
- Automatische Token-Erneuerung per Konsolen-Befehl
- Ablauf-Monitoring mit konfigurierbaren E-Mail-Warnungen
- Erweiterbar durch eigene Provider-Implementierungen

---

## Installation

```bash
composer require wapplersystems/oauth-service
```

Anschließend Datenbank-Schema aktualisieren (TYPO3-Installationswerkzeug oder Konsole):

```bash
php typo3 database:updateschema
```

Das Backend-Modul erscheint danach unter **System → OAuth Services**.

---

## Konfiguration

Die Extension-Konfiguration befindet sich unter
**Admin-Tools → Einstellungen → Extension-Konfiguration → oauth_service**.

| Parameter | Standard | Beschreibung |
|---|---|---|
| `thresholdSeconds` | `300` | Token-Erneuerung wird angestoßen, wenn das Token in weniger als X Sekunden abläuft |
| `debounceMinutes` | `360` | Mindestabstand in Minuten zwischen zwei Fehler-Benachrichtigungen je Verbindung |
| `warningEmail` | *(leer)* | Kommagetrennte E-Mail-Adressen für Ablauf-Warnungen |
| `warningThresholdDays` | `7,3,1` | Kommagetrennte Tage vor Ablauf, an denen Warn-Mails verschickt werden |
| `debounceHours` | `20` | Mindestabstand in Stunden zwischen Warn-Mails je Verbindung |

---

## Backend-Modul

Das Modul ist unter **System → OAuth Services** erreichbar (nur für Administratoren).

Die Übersichtsseite zeigt alle konfigurierten Clients mit ihren aktiven Verbindungen. Je Client werden angezeigt:

- Provider-Bezeichnung und Client-ID
- Status jeder Verbindung (`connected`, `expired`, `error`, `disconnected`)
- Token-Ablaufzeitpunkt und Restlaufzeit
- Zeitpunkt der letzten Erneuerung und letzten Prüfung
- Letzte Fehlermeldung (falls vorhanden)

---

## Clients anlegen

Ein Client repräsentiert eine registrierte OAuth-Anwendung beim Provider (z. B. eine App bei Google oder GitHub).

1. Im Backend-Modul **„New Client"** wählen.
2. **Provider auswählen** – die verfügbaren Provider werden von der jeweiligen Extension registriert (z. B. `cleverreach`).
3. **Zugangsdaten eintragen:**
   - **Client ID** – die App-ID des OAuth-Providers
   - **Client Secret** – das App-Geheimnis (wird verschlüsselt gespeichert)
   - **Scopes** – benötigte Berechtigungen (Leerzeichen- oder kommagetrennt, alternativ als JSON-Array)
   - **Notification Email** – E-Mail-Adresse für Fehler-Benachrichtigungen bei Token-Problemen

---

## Verbindungen verwalten

Eine Verbindung entsteht durch den OAuth2-Autorisierungsfluss (Authorization Code Flow).

### Verbindung herstellen

1. Im Backend-Modul beim gewünschten Client auf **„Connect"** klicken.
2. Der Browser wird zur Autorisierungsseite des Providers weitergeleitet.
3. Nach der Bestätigung leitet der Provider zurück zu TYPO3.
4. Die Verbindung erscheint mit Status `connected`.

### Verbindungsstatus

| Status | Bedeutung |
|---|---|
| `connected` | Verbindung aktiv, Token gültig |
| `expired` | Token abgelaufen, Erneuerung ausstehend |
| `error` | Letzter Refresh oder Callback hat einen Fehler geliefert |
| `disconnected` | Verbindung manuell getrennt |

### Verbindung erneuen

Bei abgelaufenen oder fehlerhaften Verbindungen steht **„Reconnect"** zur Verfügung, um den Autorisierungsfluss erneut zu durchlaufen.

### Verbindung trennen

**„Disconnect"** entfernt die gespeicherten Tokens. Der Client-Eintrag bleibt erhalten.

---

## Konsolen-Befehle

### Token-Erneuerung

```bash
php typo3 oauth-service:refresh-tokens [Optionen]
```

Erneuert ablaufende oder abgelaufene Access-Tokens über den Refresh-Token-Fluss.

| Option | Beschreibung |
|---|---|
| `-t, --threshold <Sekunden>` | Erneuert Token, die in weniger als X Sekunden ablaufen (Standard: `thresholdSeconds` aus Extension-Konfiguration) |
| `--uid <UID>` | Nur eine bestimmte Verbindung erneuern |
| `-f, --force` | Alle Verbindungen erneuern, unabhängig vom Ablaufzeitpunkt |

**Empfohlenes Scheduler-Intervall:** alle 5 Minuten.

```bash
# Alle ablaufenden Token erneuern (Standardschwelle)
php typo3 oauth-service:refresh-tokens

# Token für eine bestimmte Verbindung erzwingen
php typo3 oauth-service:refresh-tokens --uid 3 --force

# Token erneuern, die in weniger als 10 Minuten ablaufen
php typo3 oauth-service:refresh-tokens --threshold 600
```

### Verbindungs-Monitoring

```bash
php typo3 oauth-service:monitor-connections
```

Prüft alle aktiven Verbindungen auf bevorstehenden Token-Ablauf und verschickt Warn-Mails an die in `warningEmail` konfigurierten Adressen.

**Empfohlenes Scheduler-Intervall:** einmal täglich.

Warn-Mails werden nur verschickt, wenn:
- der Ablaufzeitpunkt innerhalb eines der konfigurierten Schwellwerte (`warningThresholdDays`) liegt, **und**
- seit der letzten Benachrichtigung mindestens `debounceHours` vergangen sind.

---

## Provider registrieren

Andere Extensions oder site-spezifischer Code registrieren Provider, die im Backend-Modul zur Auswahl stehen.

### Registrierung via Services.php

```php
// EXT:my_extension/Configuration/Services.php

use WapplerSystems\OauthService\Provider\ProviderDefinition;
use WapplerSystems\OauthService\Provider\ProviderRegistryInterface;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->addCompilerPass(
        new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void {
                $registry = $container->findDefinition(ProviderRegistryInterface::class);
                $registry->addMethodCall('register', [
                    new Definition(ProviderDefinition::class, [
                        'my_provider',                              // identifier (eindeutig)
                        'My Provider',                             // title (Anzeigename)
                        'generic_oauth2',                          // type
                        'https://provider.example/oauth/authorize', // authorizationUrl
                        'https://provider.example/oauth/token',    // tokenUrl
                        ['read', 'write'],                         // defaultScopes (optional)
                    ]),
                ]);
            }
        }
    );
};
```

### Parameter der ProviderDefinition

| Parameter | Typ | Beschreibung |
|---|---|---|
| `identifier` | `string` | Eindeutiger Bezeichner, wird in der Datenbank gespeichert |
| `title` | `string` | Anzeigename im Backend-Modul |
| `type` | `string` | Provider-Typ-Implementierung (Standard: `generic_oauth2`) |
| `authorizationUrl` | `string` | OAuth2-Autorisierungs-Endpunkt des Providers |
| `tokenUrl` | `string` | OAuth2-Token-Endpunkt des Providers |
| `defaultScopes` | `array` | Vorausgewählte Scopes (können im Client überschrieben werden) |

---

## Integration in andere Extensions

### Aktive Verbindung nach Provider abrufen

`OAuthClientService::getActiveConnectionByProvider()` liefert die erste aktive Verbindung eines Providers mit bereits entschlüsseltem Access-Token:

```php
use WapplerSystems\OauthService\Service\OAuthClientService;

class MyService
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
    ) {}

    public function getAccessToken(string $provider): string
    {
        $connection = $this->oAuthClientService->getActiveConnectionByProvider($provider);

        if ($connection === null) {
            throw new \RuntimeException('Keine aktive Verbindung für Provider "' . $provider . '"');
        }

        return $connection['access_token'];
    }
}
```

### Aktive Clients als Select-Optionen laden

Der `OAuthClientService` liefert aktive Clients eines Providers als Array für Select-Felder (z. B. im Form-Editor-Backend):

```php
use WapplerSystems\OauthService\Service\OAuthClientService;

class MyController
{
    public function __construct(
        private readonly OAuthClientService $oAuthClientService,
    ) {}

    public function getClientOptions(): array
    {
        // Gibt [['value' => '1', 'label' => 'Mein Client (my-client-id)'], ...] zurück
        return $this->oAuthClientService->getActiveClientsAsOptions('my_provider');
    }
}
```

### Datenbankschema-Übersicht

| Tabelle | Inhalt |
|---|---|
| `tx_oauthsvc_client` | OAuth-App-Konfigurationen (Client-ID, Secret, Scopes) |
| `tx_oauthsvc_connection` | Aktive Verbindungen mit verschlüsselten Tokens und Status |

Beide Tabellen sind global (`rootLevel: -1`) und nicht an eine bestimmte Seite gebunden.