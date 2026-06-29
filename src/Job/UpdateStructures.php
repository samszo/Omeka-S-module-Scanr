<?php declare(strict_types=1);

namespace Scanr\Job;

use Omeka\Job\AbstractJob;

/**
 * Job wrapper pour StructuresUpdater.
 *
 * Paramètres optionnels :
 *   - item_id  (int)    : traite un seul item
 *   - json_path (string): chemin vers le fichier JSON MESR
 */
class UpdateStructures extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $updater  = $services->get('Scanr\StructuresUpdater');

        $updater->run(
            [
                'item_id'   => $this->getArg('item_id'),
                'json_path' => $this->getArg('json_path'),
            ],
            fn() => $this->shouldStop()
        );
    }
}
