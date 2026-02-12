<?php
namespace ScanR;

use Omeka\Module\AbstractModule;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        // Installation logic if needed
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        // Uninstallation logic if needed
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        
        return $renderer->render('scanr/module/config', [
            'scanr_api_url' => $settings->get('scanr_api_url', 'https://scanr-api.enseignementsup-recherche.gouv.fr'),
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $params = $controller->params()->fromPost();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        
        $settings->set('scanr_api_url', $params['scanr_api_url']);
        
        return true;
    }
}
