<?php
namespace Scanr\Service;

use Laminas\Http\Client;
use Laminas\Http\Request;

class Geocoding
{
    protected $httpClient;

    public function __construct(Client $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Géocode une adresse via l'API Adresse (BAN).
     *
     * @return array|null ['lat' => float, 'lng' => float, 'label' => string]
     */
    public function geocodeAddress(string $address): ?array
    {
        if (empty(trim($address))) {
            return null;
        }

        try {
            $this->httpClient->resetParameters();
            $this->httpClient->setUri('https://api-adresse.data.gouv.fr/search/');
            $this->httpClient->setMethod(Request::METHOD_GET);
            $this->httpClient->setParameterGet([
                'q'     => $address,
                'limit' => 1,
            ]);

            $response = $this->httpClient->send();

            if ($response->isSuccess()) {
                $data = json_decode($response->getBody(), true);
                if (!empty($data['features'])) {
                    $feature = $data['features'][0];
                    return [
                        'lat'   => (float) $feature['geometry']['coordinates'][1],
                        'lng'   => (float) $feature['geometry']['coordinates'][0],
                        'label' => $feature['properties']['label'] ?? $address,
                    ];
                }
            }
        } catch (\Exception $e) {
            // géocodage silencieux : l'appelant gère l'absence de résultat
        }

        return null;
    }

    /**
     * Construit l'adresse postale d'un item Omeka à partir de ses propriétés.
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    public function addressFromItem($item): string
    {
        $parts = array_filter([
            $this->val($item, 'schema:address'),
            $this->val($item, 'schema:postalCode'),
            $this->val($item, 'schema:addressLocality'),
        ]);

        return implode(', ', $parts);
    }

    private function val($item, string $term): string
    {
        $v = $item->value($term);
        return $v ? trim((string) $v) : '';
    }
}