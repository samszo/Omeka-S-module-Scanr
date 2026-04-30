<?php declare(strict_types=1);

namespace Scanr;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'api_adapters' => [
        'invokables' => [
            'scanr_expertises' => Api\Adapter\ExpertiseAdapter::class,
        ],
    ],

    'service_manager' => [
        'factories' => [
            'Scanr\ApiClient'  => Service\ApiClientFactory::class,
            'Scanr\DuckClient' => Service\DuckClientFactory::class,
            'Scanr\JsonlClient'=> Service\JsonlClientFactory::class,
            'Scanr\SqlClient'  => Service\SqlClientFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Scanr\Controller\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Scanr',
                'route' => 'admin/scanr',
                'resource' => 'Scanr\Controller\Index',
                'class' => 'o-icon- fa-users',
            ],
        ],
    ],

    'form_elements' => [
        'factories' => [
            Form\SearchForm::class => Service\Form\SearchFormFactory::class,
        ],
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\BatchEditFieldset::class => Form\BatchEditFieldset::class,
            Form\UserSettingsFieldset::class  => Form\UserSettingsFieldset::class,
        ],
    ],

    'scanr' => [
        'config' => [
            'scanr_url' => "https://scanr-api.enseignementsup-recherche.gouv.fr",
            'scanr_username' => "",
            'scanr_pwd' => "",
            'scanr_properties_fullName' => ["foaf:accountName"],
            'scanr_class_person' => ["foaf:Person"],
            'scanr_template_person' => [],
            'scanr_itemset_person' => [],
            'scanr_class_structure' => ["foaf:Organization"],
            'scanr_properties_hasStructure' => ["foaf:member"],
            'scanr_class_concept' => ["skos:concept"],
            'scanr_properties_conceptLabel' => ["skos:prefLabel"],
            'scanr_properties_hasConcept' => ["dcterms:subject"],
            'scanr_json_path' => dirname(__DIR__) . '/data/persons_denormalized.jsonl.gz',
            'scanr_json_import' => false,
            
        ],
        'user_settings' => [
            'scanr_labos_admin' => '',
            'scanr_creator_id'  => '',
        ],

    ],


    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'scanr' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/scanr',
                            'defaults' => [
                                '__NAMESPACE__' => 'Scanr\Controller',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'search' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/search',
                                    'defaults' => [
                                        'action' => 'search',
                                    ],
                                ],
                            ],
                            'import' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import',
                                    'defaults' => [
                                        'action' => 'import',
                                    ],
                                ],
                            ],
                            'associer' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/associer',
                                    'defaults' => [
                                        'action' => 'associer',
                                    ],
                                ],
                            ],
                            'import-jsonl' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import-jsonl',
                                    'defaults' => [
                                        'action' => 'importJsonl',
                                    ],
                                ],
                            ],
                            'expertise-ajax' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/expertise-ajax',
                                    'defaults' => [
                                        'action' => 'expertiseAjax',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
