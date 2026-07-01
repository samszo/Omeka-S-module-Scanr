<?php
namespace Scanr\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OrcidClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new OrcidClient(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\HttpClient')
        );
    }
}
