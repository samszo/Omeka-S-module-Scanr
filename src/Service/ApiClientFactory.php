<?php
namespace Scanr\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ApiClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $connection = $services->get('Omeka\Connection');
        $entityManager = $services->get('Omeka\EntityManager');

        return new ApiClient($settings, $api, $logger, $connection, $entityManager);
    }
}
