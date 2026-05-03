<?php
namespace Scanr\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Scanr\Form\UserSettingsFieldset;

class UserSettingsFieldsetFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings = $container->get('Omeka\Settings');
        $api = $container->get('Scanr\ApiClient');
        $auth = $container->get('Omeka\AuthenticationService');

        return new UserSettingsFieldset($settings,$api,$auth);
    }
}