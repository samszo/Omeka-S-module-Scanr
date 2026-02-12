<?php
namespace ScanR\Form;

use Laminas\Form\Form;
use Laminas\Form\Element;

class SearchForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'query',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Rechercher une personne',
            ],
            'attributes' => [
                'id' => 'query',
                'required' => true,
                'placeholder' => 'Nom, prénom ou affiliation...',
            ],
        ]);

        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Rechercher',
                'id' => 'submit',
            ],
        ]);
    }
}
