<?php
namespace Scanr\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class JsonlClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new JsonlClient(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Logger')
        );
    }
}
