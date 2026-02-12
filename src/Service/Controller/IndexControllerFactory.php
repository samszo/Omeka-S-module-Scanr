<?php
namespace ScanR\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ScanR\Controller\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $apiClient = $services->get('ScanR\ApiClient');
        $formElementManager = $services->get('FormElementManager');
        $searchForm = $formElementManager->get(\ScanR\Form\SearchForm::class);
        $api = $services->get('Omeka\ApiManager');
        
        return new IndexController($apiClient, $searchForm, $api);
    }
}
