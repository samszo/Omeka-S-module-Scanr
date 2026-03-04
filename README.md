# Omeka-S-module-Scanr

Module Omeka S pour interroger l'API Elasticsearch de scanR et enregistrer les informations liées aux personnes.

## Description

Ce module permet d'interroger l'API scanR du Ministère de l'Enseignement Supérieur et de la Recherche française pour rechercher et importer des informations sur des personnes (chercheurs, enseignants-chercheurs, etc.) directement dans Omeka S.

scanR est une plateforme qui recense les acteurs de la recherche et de l'innovation en France, incluant des informations sur les chercheurs, leurs affiliations, domaines de recherche, publications et distinctions.

## Architecture

### Composants du module

```mermaid
graph TB
    subgraph "Interface Utilisateur"
        UI[Vue Omeka S]
        SearchForm[Formulaire de recherche]
        ConfigForm[Formulaire de configuration]
    end
    
    subgraph "Contrôleur"
        Controller[IndexController]
    end
    
    subgraph "Services"
        ApiClient[ApiClient<br/>Client Elasticsearch]
    end
    
    subgraph "API Externe"
        ScanrAPI[API scanR<br/>Elasticsearch]
    end
    
    subgraph "Stockage Omeka S"
        OmekaAPI[API Omeka S]
        Items[Items Omeka<br/>Personnes importées]
    end
    
    UI --> SearchForm
    UI --> ConfigForm
    SearchForm --> Controller
    Controller --> ApiClient
    ApiClient --> ScanrAPI
    Controller --> OmekaAPI
    OmekaAPI --> Items
    
    style UI fill:#e1f5ff
    style Controller fill:#fff4e1
    style ApiClient fill:#ffe1f5
    style ScanrAPI fill:#e1ffe1
    style OmekaAPI fill:#f5e1ff
```

### Flux de travail

```mermaid
sequenceDiagram
    participant User as Utilisateur
    participant Form as SearchForm
    participant Ctrl as IndexController
    participant API as ApiClient
    participant Scanr as API scanR
    participant Omeka as API Omeka S
    
    User->>Form: Saisit un nom/prénom
    Form->>Ctrl: Soumet la recherche
    Ctrl->>API: searchPersons(query)
    API->>Scanr: Requête Elasticsearch
    Scanr-->>API: Résultats JSON
    API-->>Ctrl: Données formatées
    Ctrl-->>User: Affiche les résultats
    
    User->>Ctrl: Clique sur "Importer"
    Ctrl->>API: getPersonDetails(id)
    API->>Scanr: Récupère détails complets
    Scanr-->>API: Données personne
    API-->>Ctrl: Métadonnées formatées
    Ctrl->>Omeka: create(item, metadata)
    Omeka-->>Ctrl: Item créé
    Ctrl-->>User: Confirmation import
```

## Fonctionnalités

- **Recherche de personnes** : Recherche par nom, prénom, affiliation ou domaine de recherche
- **Affichage des résultats** : Visualisation des informations principales (nom, affiliations, domaines)
- **Import dans Omeka S** : Création automatique d'items avec les métadonnées des personnes
- **Configuration flexible** : URL de l'API configurable

## Installation

1. Téléchargez ou clonez ce module dans le répertoire `modules` de votre installation Omeka S
2. Renommez le dossier en `Scanr` si nécessaire
3. Avec un terminal, dans le repertoire `Scanr`, exécuter : composer install --no-dev
4. Dans l'interface d'administration d'Omeka S, allez dans Modules
5. Trouvez "Scanr" dans la liste et cliquez sur "Installer"

## Configuration

Après l'installation, vous pouvez configurer le module :

1. Cliquez sur "Configurer" à côté du module Scanr
2. Modifiez l'URL de l'API si nécessaire (par défaut : `https://scanr-api.enseignementsup-recherche.gouv.fr`)
3. Enregistrez les modifications

## Utilisation

### Rechercher des personnes

1. Dans le menu d'administration, cliquez sur "Scanr"
2. Cliquez sur "Rechercher des personnes"
3. Entrez un nom, prénom, ou affiliation dans le champ de recherche
4. Cliquez sur "Rechercher"

### Importer une personne

1. Après avoir effectué une recherche, parcourez les résultats
2. Cliquez sur le bouton "Importer" à côté de la personne souhaitée
3. La personne sera créée comme un nouvel item dans Omeka S avec les métadonnées suivantes :
   - Titre (nom complet)
   - Prénom (foaf:firstName)
   - Nom (foaf:lastName)
   - Identifiant scanR (dcterms:identifier)
   - Domaines de recherche (dcterms:subject)
   - Affiliations (dcterms:description)

## Structure des données

Les données importées depuis scanR incluent :

- **Informations personnelles** : Prénom, nom, nom complet
- **Identifiant unique** : ID scanR
- **Affiliations** : Structures de rattachement (universités, laboratoires, etc.)
- **Domaines de recherche** : Thématiques scientifiques
- **Publications** : Liste des publications (si disponible)
- **Distinctions** : Prix et récompenses (si disponible)

### Mapping des métadonnées

```mermaid
graph LR
    subgraph "Données scanR"
        ScanrID[id]
        ScanrFullName[fullName]
        ScanrFirstName[firstName]
        ScanrLastName[lastName]
        ScanrAffiliations[affiliations]
        ScanrDomains[domains]
        ScanrPublications[publications]
        ScanrAwards[awards]
    end
    
    subgraph "Item Omeka S"
        Title[dcterms:title]
        ID[dcterms:identifier]
        FirstName[foaf:firstName]
        LastName[foaf:lastName]
        Desc[dcterms:description]
        Subject[dcterms:subject]
    end
    
    ScanrFullName --> Title
    ScanrID --> ID
    ScanrFirstName --> FirstName
    ScanrLastName --> LastName
    ScanrAffiliations --> Desc
    ScanrDomains --> Subject
    
    style ScanrID fill:#e1ffe1
    style ScanrFullName fill:#e1ffe1
    style ScanrFirstName fill:#e1ffe1
    style ScanrLastName fill:#e1ffe1
    style ScanrAffiliations fill:#e1ffe1
    style ScanrDomains fill:#e1ffe1
    style Title fill:#e1f5ff
    style ID fill:#e1f5ff
    style FirstName fill:#e1f5ff
    style LastName fill:#e1f5ff
    style Desc fill:#e1f5ff
    style Subject fill:#e1f5ff
```

## API scanR

Ce module utilise l'API Elasticsearch publique de scanR. Pour plus d'informations sur scanR :
- Site web : https://scanr.enseignementsup-recherche.gouv.fr
- Documentation API : https://scanr-api.enseignementsup-recherche.gouv.fr

## Exigences

- Omeka S 3.0 ou supérieur
- PHP 7.4 ou supérieur
- Extension PHP cURL activée

## Auteur

Samuel Szoniecky

## Licence

GPL-3.0

## Support

Pour signaler des bugs ou demander des fonctionnalités, veuillez utiliser le système d'issues de GitHub : https://github.com/samszo/Omeka-S-module-Scanr/issues