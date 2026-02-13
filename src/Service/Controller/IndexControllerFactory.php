<?php
namespace Scanr\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Scanr\Controller\IndexController;


class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $apiClient = $services->get('Scanr\ApiClient');
        $formElementManager = $services->get('FormElementManager');
        $searchForm = $formElementManager->get(\Scanr\Form\SearchForm::class);
        $api = $services->get('Omeka\ApiManager');

        $indexController = new IndexController($apiClient, $searchForm, $api);
        
        return $indexController;
    }
}
