<?php
namespace Scanr\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;
use Laminas\Http\Client;

class GeocodingFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        // On récupère le client HTTP partagé configuré dans Omeka
        $httpClient = $container->get('Omeka\HttpClient');
        return new Geocoding($httpClient);
    }
}