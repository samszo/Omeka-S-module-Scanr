<?php declare(strict_types=1);

namespace Scanr\Form;

use Laminas\Form\Fieldset;
use Common\Form\Element as CommonElement;
use Laminas\Authentication\AuthenticationService;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Scanr'; // @translate

    protected $elementGroups = [
        'scanr' => 'Scanr', // @translate
    ];

    
    protected $settings;
    protected $api;
    /**
     * @var AuthenticationService
     */
    protected $auth;

    // Inject the service through the constructor
    public function __construct($settings, $api, $auth, $name = 'UserSettingsFieldset', $options = [])
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->auth = $auth;
        
        // Always call the parent constructor!
        parent::__construct($name, $options);
    }
    //

    public function init(): void
    {
        //pour vérifier le rôle de l'utilisateur et afficher la sélection des labos
        $user =  $this->auth->getIdentity();
        $role = $user->getRole();
        if($role!="global_admin")return;
        //pour récupérer la class des labos
        $classLabo = $this->settings->get('scanr_class_labo')[0];
        $rc = $this->api->getRc($classLabo);
        $this
            ->setAttribute('id', 'scanr')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'scanr_labos_admin',
                'type' => CommonElement\OptionalResourceSelect::class,
                'options' => [
                    'label' => 'Administration laboratoire(s)', // @translate
                    'info' => 'Sélectionnez les laboratoires dont cet utilisateur est responsable.', // @translate

                    'disable_group_by_owner' => true,
                    /*
                    'prepend_value_options' => [
                        '' => 'Manual selection (default)', // @translate
                        'none' => 'No value annotation', // @translate
                    ],
                    */
                    'resource_value_options' => [
                        'resource' => 'items',
                        'query' => ["resource_class_id"=>$rc->id()],
                        'option_text_callback' => function ($resource) {
                            return $resource->displayTitle();
                        },
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_labos_admin',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Selectionner un/des laboratoire(s)', // @translate
                    'multiple' => true,
                    'value' => [],
                ],
            ])
            ;


        ;
    }
}
