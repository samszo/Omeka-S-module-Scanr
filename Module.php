<?php
namespace Scanr;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\TraitModule;
use Omeka\Module\AbstractModule;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{

    const NAMESPACE = __NAMESPACE__;
    use TraitModule;

    protected $dependencies = [
        'Common',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
    }

    protected function preInstall(): void
    {
        /** @var \Laminas\Mvc\I18n\Translator $translator */
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');
        $plugins = $services->get('ControllerPluginManager');
        $messenger = $plugins->get('messenger');

        /*
        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Scanr', '1.0.0.1')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Scanr', '1.0.0.1'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
        */

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!$this->checkDestinationDir($basePath . '/backup/log')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable, so old logs cannot be archived.', // @translate
                ['directory' => $basePath . '/backup/log']
            );
            $messenger->addWarning($message);
        }
    }


    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'formAddElementsResourceBatchUpdateForm']
        );

    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        return $this->getConfigFormAuto($renderer);
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        // $resourceType = $form->getOption('resource_type');

        $fieldset = $formElementManager->get(BatchEditFieldset::class);

        $form->add($fieldset);
    }

    /**
     * Vérifie puis lance la tâche
     */
    public function handleResourceBatchUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        if (empty($data['mailing']['mailing_merge_to_listmonk'])) {
            return;
        }

        $ids = (array) $request->getIds();
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return;
        }

        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $url = $plugins->get('url');
        $messenger = $plugins->get('messenger');
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $params = [
            'ids' => $ids,
            'idFirst' => $ids[0],
            'idLast' => $ids[count($ids)-1],
        ];


        if(!empty($data['mailing']['mailing_merge_to_listmonk'])){
            $params['pipeline'] = "merge_to_listmonk";
            $this->createJob(\Mailing\Job\mergeItemDataToSubscribters::class, $params, $url, $dispatcher, $messenger);                
        }
        
   }

    function createJob($jobName, $params, $url, $dispatcher, $messenger): void
    {
        $job = $dispatcher->dispatch($jobName, $params);
        $message = new \Omeka\Stdlib\Message(
            $params['pipeline'].' via a '.$params['service'].' derivated background job='.$job->getId()
            . ' ids='.$params['idFirst'].' -> '.$params['idLast']
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

}
