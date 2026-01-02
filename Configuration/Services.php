<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use WapplerSystems\OauthService\Attribute\OAuthProviderType;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder) {
    $containerBuilder->registerAttributeForAutoconfiguration(
        OAuthProviderType::class,
        static function (ChildDefinition $definition, OAuthProviderType $attribute): void {
            $definition->addTag(OAuthProviderType::TAG_NAME, ['identifier' => $attribute->identifier]);
        }
    );
};
