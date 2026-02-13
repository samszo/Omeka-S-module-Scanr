<?php declare(strict_types=1);

namespace Scanr\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'scanr_url',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "URL de l'API scanR",
                    'info' => "URL de base pour l'API Elasticsearch de scanR (par défaut: https://scanr-api.enseignementsup-recherche.gouv.fr)",
                ],
                'attributes' => [
                    'id' => 'scanr_url',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_username',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Scanr Username',
                    'info' => 'Username for Scanr API authentication',
                ],
                'attributes' => [
                    'id' => 'scanr_username',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_pwd',
                'type' => Element\Password::class,
                'options' => [
                    'label' => 'Scanr Password',
                    'info' => 'Password for Scanr API authentication',
                ],
                'attributes' => [
                    'id' => 'scanr_pwd',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_properties_fullName',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties to search person', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_fullName',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
        ;

    }
}
