<?php
namespace Scanr;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}


use Common\TraitModule;
use Omeka\Module\AbstractModule;
use Common\Stdlib\PsrMessage;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\MvcEvent;
use Laminas\EventManager\Event;
use Scanr\Form\BatchEditFieldset;
use Scanr\Form\UserSettingsFieldset;

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

        if (!class_exists(\Common\ManageModuleAndResources::class, false)) {
            require_once dirname(__DIR__) . '/Common/src/ManageModuleAndResources.php';
        }

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
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            [$this, 'addExpertisesTab']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            [$this, 'renderExpertisesTab']
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

                // Ajout du paramètre utilisateur
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'handleUserSettings']
        );


    }


    /**
     * Empèche les utilisateurs de voir le compte Google d'un autre utilisateur,
     * y compris l'admin.
     */
    public function handleUserSettings(Event $event): void
    {
        $this->handleAnySettings($event, 'user_settings');
    }



    public function getConfigForm(PhpRenderer $renderer)
    {
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

        if (empty($data['scanr']['scanr_merge'])) {
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


        if(!empty($data['scanr']['scanr_merge'])){
            $params['pipeline'] = "merge_from_scanr";
            $this->createJob(\Scanr\Job\addScanrData::class, $params, $url, $dispatcher, $messenger);                
        }
        
   }

    function createJob($jobName, $params, $url, $dispatcher, $messenger): void
    {
        $job = $dispatcher->dispatch($jobName, $params);
        $message = new \Omeka\Stdlib\Message(
            $params['pipeline'].' derivated background job='.$job->getId()
            . ' ids='.$params['idFirst'].' -> '.$params['idLast']
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    public function addExpertisesTab(Event $event): void
    {
        $view = $event->getTarget();
        $item = $this->isPerson($view);
        if (!$item) return;
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['scanr-expertises'] = 'Expertises'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    public function renderExpertisesTab(Event $event): void
    {
        $view = $event->getTarget();
        $item = $this->isPerson($view);
        if (!$item) return;
        echo $view->partial('scanr/item/expertises-tab', ['item' => $item]);
    }

    public function isPerson($view){
        $item = $view->vars()->offsetGet('item');
        //affiche l'onglet que pour les items d'enseignant chercheur
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $classPerson = $settings->get('scanr_class_person')[0];
        $classItem = $item->resourceClass()->term();
        if (!$item || $classPerson != $classItem) {
            return false;
        }else{
            return $item;
        }

    }

}
