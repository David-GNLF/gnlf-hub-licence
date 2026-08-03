# gnlf/hub-licence

Client d'activation du Hub GNLF Consult, embarqué dans les applications Laravel
de la flotte (DigiClinic, EduManager, DigiCredit).

L'application « appelle la maison » (ping) pour obtenir ou rafraîchir sa licence
— un JWT signé RS256 par la clé privée du produit — puis la vérifie **hors
ligne**. Une coupure réseau ne bloque jamais une instance détentrice d'un jeton
encore valide ; une révocation prend effet au ping suivant.

## Pourquoi ce paquet existe

Ce client vivait en quatre exemplaires recopiés, un par solution. Ils ont
dérivé. Le 03/08/2026, la même auto-censure — « je ne publie pas mon catalogue
si je m'authentifie par secret d'instance » — était **présente dans deux
applications, corrigée dans une troisième, et n'avait jamais existé dans la
quatrième**. Trois pannes silencieuses en une journée, diagnostiquées une par
une.

La mesure faite avant l'extraction : sur 20 méthodes communes, **18 étaient
identiques au caractère près**, `ping` à 97–99 %, et seule `plansAnnonces`
divergeait réellement. Il n'y avait donc rien à arbitrer — juste à cesser de
recopier.

## Installation

Dépôt privé : déclarer la source et exiger le paquet.

```jsonc
// composer.json de l'application
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/David-GNLF/gnlf-hub-licence" }
    ],
    "require": {
        "gnlf/hub-licence": "^1.0"
    }
}
```

`composer install` doit pouvoir lire le dépôt : poser un jeton de lecture
(`COMPOSER_AUTH`, ou `composer config --global github-oauth.github.com <jeton>`)
sur le poste et dans la CI. **C'est l'étape qu'on oublie** : sans elle,
`composer install` échoue au build de l'image, pas au commit.

## Utilisation

L'application n'hérite que de ceci — tous les appels existants restent valides :

```php
namespace App\Hub;

class LicenceHub extends \Gnlf\HubLicence\LicenceHub {}
```

## Les deux points d'extension

Ce qui est propre à chaque produit passe par la configuration, **jamais par une
surcharge de méthode** : une surcharge, c'est une dérive qui recommence.

| Clé de config | Rôle | Contrat |
|---|---|---|
| `hub_licence.inventaire` | parc géré par l'instance | `__invoke(): ['institutions' => ?int, 'centres' => ?int]` |
| `hub_licence.catalogue` | tarifs à refléter au Hub | `__invoke(): array` de `['code', 'nom', 'prix', 'periode', 'limites'?, 'actif'?]` |

Les deux sont **facultatifs** et tolérants : classe absente, table absente,
exception → la donnée n'est pas rapportée et le ping continue. Ni un inventaire
ni un catalogue ne doivent jamais coûter une licence.

### Ne déduisez jamais votre rôle d'un indice local

Ne filtrez pas d'après le **type de secret** dont vous disposez. C'est
exactement ce qui a fait taire deux serveurs centraux, chacun se croyant à tort
un simple boîtier client parce qu'il s'authentifiait par secret d'instance.
**L'instance publie, le Hub arbitre** : le droit de publier est porté par le
contrat côté Hub (`abonnements.publie_le_catalogue`), qui seul sait quel
déploiement parle au nom du produit.

En revanche, filtrer sur la **provenance de la donnée** est légitime : un
boîtier edge détient une réplique du catalogue de son central, la republier
ferait remonter deux fois la même chose. La distinction tient à ceci — « d'où
vient cette donnée ? » se répond localement, « ai-je le droit de parler ? » non.

## Clés de service

Le Hub distribue les clés tierces (assistant IA…) à chaque ping. Pour les
appliquer, dans un `ServiceProvider` :

```php
\App\Hub\LicenceHub::appliquerClesService([
    'services.groq.key' => 'GROQ_API_KEY',
]);
```

**Une valeur locale l'emporte toujours** : le Hub comble les manques, il ne
réécrit pas le `.env` de la machine. Ce qui a réellement été appliqué repart au
ping suivant (`cles_appliquees`), ce qui permet au Hub de distinguer « livrée »
de « appliquée » — sans quoi une clé peut arriver et rester lettre morte
pendant des heures sans que rien ne le montre. C'est arrivé.

## Ordres du Hub

`ACTIONS_CONNUES` est une allow-list revalidée **côté client**. Le Hub propose,
la machine dispose : un Hub compromis ne peut pas faire exécuter ici une action
absente de cette liste, laquelle ne contient par construction rien de
destructif.
