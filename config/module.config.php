<?php declare(strict_types=1);

namespace Scanr;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],

    'service_manager' => [
        'factories' => [
            'Scanr\ApiClient' => Service\ApiClientFactory::class,
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
        ],
    ],

    'scanr' => [
        'config' => [
            'scanr_url' => "https://scanr-api.enseignementsup-recherche.gouv.fr",
            'scanr_username' => "",
            'scanr_pwd' => "",
            'scanr_properties_fullName' => ["foaf:accountName"],
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
                        ],
                    ],
                ],
            ],
        ],
    ],
];
