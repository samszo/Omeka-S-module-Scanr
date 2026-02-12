<?php
namespace ScanR;

return [
    'service_manager' => [
        'factories' => [
            'ScanR\ApiClient' => Service\ApiClientFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\SearchForm::class => Service\Form\SearchFormFactory::class,
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
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/scanr',
                            'defaults' => [
                                '__NAMESPACE__' => 'ScanR\Controller',
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
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'ScanR',
                'route' => 'admin/scanr',
                'resource' => 'ScanR\Controller\Index',
                'pages' => [
                    [
                        'label' => 'Rechercher des personnes',
                        'route' => 'admin/scanr/search',
                        'resource' => 'ScanR\Controller\Index',
                    ],
                ],
            ],
        ],
    ],
];
