<?php
namespace Scanr\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Scanr\Controller\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $formElementManager = $services->get('FormElementManager');

        return new IndexController(
            $services->get('Omeka\AuthenticationService'),
            $services->get('Scanr\ApiClient'),
            $services->get('Scanr\JsonlClient'),
            $services->get('Scanr\DuckClient'),
            $services->get('Scanr\SqlClient'),
            $formElementManager->get(\Scanr\Form\SearchForm::class),
            $services->get('Omeka\ApiManager'),
            $services->get(\Omeka\Job\Dispatcher::class),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\User')
        );
    }
}
