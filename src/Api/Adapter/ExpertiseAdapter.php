<?php declare(strict_types=1);

namespace Scanr\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\ItemAdapter;

class ExpertiseAdapter extends ItemAdapter
{
    public function getResourceName(): string
    {
        return 'scanr_expertises';
    }

    public function getRepresentationClass(): string
    {
        return \Omeka\Api\Representation\ItemRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        // Force le filtre sur le template "Expertise"
        $query['resource_template_label'] = 'Expertise';
        parent::buildQuery($qb, $query);
    }
}
