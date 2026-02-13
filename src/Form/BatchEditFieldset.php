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
            ->setName('mailing')
            ->setOptions([
                'label' => 'Mailing', // @translate
            ])
            ->setAttributes([
                'id' => 'mailing',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'mailing_merge_to_listmonk',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Merge item data to Listmonk subscribers', // @translate
                ],
                'attributes' => [
                    'id' => 'mailing_merge_to_listmonk',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
         ;
    }
}
