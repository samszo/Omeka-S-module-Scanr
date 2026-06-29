<?php declare(strict_types=1);

namespace Scanr\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class StructuresUpdaterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new StructuresUpdater($services);
    }
}
