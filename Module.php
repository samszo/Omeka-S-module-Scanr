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
        'CAS',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        // 1. Récupérer le gestionnaire de services et le service ACL d'Omeka
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');
        
        // 2. Ajouter la règle d'autorisation
        $acl->allow(
            null,                       // Rôle : null autorise tout le monde (y compris les visiteurs invités)
            'Scanr\Controller\Index',   // Ressource : Le nom exact de votre contrôleur (tel qu'indiqué dans l'erreur)
            'expertiseAjax'             // Privilège : Le nom exact de votre action (sans le suffixe "Action")
        );

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
            [$this, 'addScanrTab']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            [$this, 'renderScanrTab']
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
     * Déclenche StructuresUpdater sur un item unique après un PATCH.
     */
    public function handleItemUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Response $response */
        $response = $event->getParam('response');
        $item     = $response->getContent();

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $classOrg = ($settings->get('scanr_class_structure') ?? ['foaf:Organization'])[0];
        $itemClass = $item->resourceClass() ? $item->resourceClass()->term() : null;

        if ($itemClass !== $classOrg) {
            return;
        }

        if (!$item->value('dcterms:isReferencedBy')) {
            return;
        }

        $updater = $services->get('Scanr\StructuresUpdater');
        $updater->run(['item_id' => $item->id()]);
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

    public function addScanrTab(Event $event): void
    {
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $item = $view->vars()->offsetGet('item');

        $this->addExpertisesTab($event,$item,$settings);
        $this->addGeocodingTab($event,$item,$settings);
    }


    public function addExpertisesTab(Event $event,$item,$settings): void
    {
        $item = $this->isPerson($item,$settings);
        if (!$item) return;
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['scanr-expertises'] = 'Expertises'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    public function addGeocodingTab(Event $event,$item,$settings): void
    {
        $item = $this->isStructure($item,$settings);
        if (!$item) return;
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['scanr-geocoding'] = 'Geocoding'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    public function renderScanrTab(Event $event): void
    {
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $item = $view->vars()->offsetGet('item');

        $this->renderExpertisesTab($event,$view,$item,$settings,$services);
        $this->renderGeocodingTab($event,$view,$item,$settings,$services);
    }

    public function renderGeocodingTab(Event $event,$view,$item,$settings,$services): void
    {
        $item = $this->isStructure($item, $settings);
        if (!$item) return;
        if (!$this->isUser($view, $services)) return;

        $api      = $services->get('Omeka\ApiManager');
        $geocoding = $services->get('Scanr\Geocoding');

        // Feature cartographique existant pour cet item
        $features   = $api->search('mapping_features', ['item_id' => $item->id()])->getContent();
        $currentLat = null;
        $currentLng = null;
        if (!empty($features)) {
            $coords     = $features[0]->geographyCoordinates(); // [lng, lat]
            $currentLng = $coords[0] ?? null;
            $currentLat = $coords[1] ?? null;
        }

        echo $view->partial('scanr/item/geocoding-tab', [
            'item'       => $item,
            'address'    => $geocoding->addressFromItem($item),
            'currentLat' => $currentLat,
            'currentLng' => $currentLng,
        ]);
    }

    public function renderExpertisesTab(Event $event,$view,$item,$settings,$services): void
    {
        $item = $this->isPerson($item, $settings);
        if (!$item) return;
        if(!$this->isUser($view, $services))return;

        $classConcept = $settings->get('scanr_class_concept')[0];
        $api = $services->get('Scanr\ApiClient');
        $rc = $api->getRc($classConcept);

        echo $view->partial('scanr/item/expertises-tab', ['item' => $item, 'classConceptId'=> $rc->id()]);
    }

    public function isPerson($item, $settings){
        //affiche l'onglet que pour les items d'enseignant chercheur
        $classPerson = $settings->get('scanr_class_person')[0];
        $classItem = $item->resourceClass() ?  $item->resourceClass()->term() : null;
        if (!$item || $classPerson != $classItem) {
            return false;
        }else{
            return $item;
        }
    }
    public function isStructure($item, $settings){
        //affiche l'onglet que pour les items d'enseignant chercheur
        $classStruc = $settings->get('scanr_class_structure')[0];
        $classItem = $item->resourceClass() ?  $item->resourceClass()->term() : null;
        if (!$item || $classStruc != $classItem) {
            return false;
        }else{
            return $item;
        }
    }

    public function isUser($view,  $services){
        $auth = $services->get('Omeka\AuthenticationService');
        $user =  $auth->getIdentity();
        return $user ? true : false;
    }


}
