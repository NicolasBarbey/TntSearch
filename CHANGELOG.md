# Changelog

## 5.0.0

Le module devient le moteur du contrat de recherche du cœur (Thelia 3.5). Marche à suivre pour monter de version : section « Monter de version vers 5.0.0 » du README.

### Ajouté

- `TntSearch\Search\TntSearchEngine`, moteur du contrat `Thelia\Domain\Search\Contract\SearchEngineInterface`, avec ses index de documents (`document--<famille>--<langue>.index`).
- Commande `tntsearch:status`.
- Reconstruction du catalogue mise en file par le module : un message `TntSearch\Search\Message\RebuildCatalogue` par lot, sur `async_heavy`, chacun mettant le suivant en file. L'index n'est marqué complet qu'après le dernier lot, quel que soit le transport.

### Modifié

- `thelia/core` `^3.5` requis.
- Les synonymes sont ceux du dictionnaire du cœur. Les groupes du module sont copiés à la mise à jour ; une copie en échec est retentée à chaque requête du back-office tant que la table `tnt_synonym` existe.
- `thelia:search:reindex --queue` : avec un transport qui ne sait pas compter ses messages, l'index n'est plus marqué complet (avertissement au journal) ; lancer la commande sans `--queue`, ou `tntsearch:indexes`.
- `WeightEvent` et `ExtendQueryEvent` ne s'appliquent plus qu'aux index des clients et des commandes.
- `Tokenizer\Tokenizer` ne résout plus les synonymes.
- `Service\Stemmer` : le portugais utilise `PortugueseStemmer` (et non plus `PolishStemmer`).

### Supprimé

Classes publiques supprimées, sans remplacement dans le module :

| Classe | Remplacement |
|---|---|
| `TntSearch\Controller\SearchController` (page `/admin/search`) | page de recherche du thème du back-office |
| `TntSearch\Controller\SynonymController` | écran des synonymes du thème du back-office |
| `TntSearch\Form\SynonymForm` | idem |
| `TntSearch\Service\Synonym` | `Thelia\Domain\Search\Dictionary\SearchDictionary` |
| `TntSearch\Tokenizer\SynonymResolverTrait` | `Thelia\Domain\Search\Contract\SearchTermExpanderInterface` |
| `TntSearch\Index\Product`, `Category`, `Content`, `Folder`, `Brand` | fournisseurs de documents du cœur (`Thelia\Domain\Search\Provider\*`) |
| `TntSearch\Search\FlexyProductSearchEngine` | `Thelia\Domain\Search\SearchService`, interrogé par Flexy |

Gabarits supprimés : `templates/backOffice/default-twig/TntSearch/search.html.twig` et `synonym.html.twig`.

Un module qui étendait l'une de ces classes, ou qui déclarait un index du catalogue par `BaseIndex`, passe par un fournisseur de documents du cœur (`SearchableDocumentProviderInterface`). `BaseIndex` reste pour les index des clients et des commandes.
