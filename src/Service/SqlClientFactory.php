<?php
namespace Scanr\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SqlClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new SqlClient(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Logger')
        );
    }
}
