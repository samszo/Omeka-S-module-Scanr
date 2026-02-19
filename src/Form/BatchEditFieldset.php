<?php declare(strict_types=1);

namespace Scanr\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        // Omeka ne gère pas les fieldsets, mais cela permet d'avoir un titre.
        $this
            ->setName('scanr')
            ->setOptions([
                'label' => 'Scanr', // @translate
            ])
            ->setAttributes([
                'id' => 'scanr',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'scanr_merge',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Merge item data with Scanr first response', // @translate
                ],
                'attributes' => [
                    'id' => 'scanr_merge',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
         ;
    }
}
