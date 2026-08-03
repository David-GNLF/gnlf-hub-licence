<?php

namespace Gnlf\HubLicence;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client d'activation Hub GNLF Consult (embarqué dans chaque application).
 *
 * Principe : l'app « appelle la maison » (ping) pour obtenir/rafraîchir sa
 * licence — un JWT signé RS256 par la clé privée du produit côté Hub — puis
 * la vérifie HORS-LIGNE avec la clé publique. Une coupure internet ne bloque
 * jamais une app détentrice d'un jeton encore valide (expiration + grâce
 * embarquées dans le jeton). La révocation prend effet au prochain ping.
 *
 * Fichier autonome : aucune dépendance hors framework (openssl + Http).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAQUET PARTAGÉ — NE PAS RECOPIER DANS UNE APPLICATION.
 *
 * Ce client vivait en quatre exemplaires, un par solution. Ils ont dérivé :
 * le 03/08/2026, la même auto-censure existait dans deux d'entre eux, avait
 * été corrigée dans un troisième et n'avait jamais existé dans le quatrième —
 * trois pannes silencieuses en une journée, chacune diagnostiquée séparément.
 *
 * L'application n'hérite que de ceci :
 *
 *     namespace App\Hub;
 *     class LicenceHub extends \Gnlf\HubLicence\LicenceHub {}
 *
 * ce qui laisse intacts tous les appels existants. Ce qui lui est propre passe
 * par la configuration : `hub_licence.inventaire` (parc géré) et
 * `hub_licence.catalogue` (tarifs). Rien d'autre ne doit être surchargé — une
 * surcharge, c'est une dérive qui recommence.
 * ─────────────────────────────────────────────────────────────────────────
 */
class LicenceHub
{
    /** États possibles retournés par etat(). */
    public const ACTIF = 'actif';
    public const GRACE = 'grace';
    public const SUSPENDU = 'suspendu';       // expiré au-delà de la grâce
    public const REVOQUE = 'revoque';         // kill-switch appris au dernier ping
    public const INVALIDE = 'invalide';       // signature fausse (altération)
    public const SANS_LICENCE = 'sans_licence'; // jamais activée, délai écoulé
    public const INACTIF = 'inactif';         // module non configuré : transparent
    /** Jamais activée, mais installée depuis peu : on laisse le temps d'activer. */
    public const ATTENTE_ACTIVATION = 'attente_activation';

    /**
     * CONTRAT des ordres que cette instance accepte d'exécuter.
     *
     * Revalidation côté client, et c'est tout l'intérêt : le Hub propose, la
     * machine du client dispose. Un Hub compromis ne peut pas faire exécuter
     * ici une action absente de cette liste — laquelle ne contient, par
     * construction, rien de destructif.
     */
    public const ACTIONS_CONNUES = ['diagnostic', 'vider-caches', 'sauvegarder', 'rafraichir-licence'];

    /** Mémo par requête pour ne pas relire/re-vérifier plusieurs fois. */
    private static ?array $memo = null;

    /**
     * État effectif de la licence, du point de vue de cette instance.
     * Retourne ['etat' => ..., 'claims' => ?array, 'expire_le' => ?int].
     */
    public static function etat(bool $forcerPing = false): array
    {
        if (self::$memo !== null && ! $forcerPing) {
            return self::$memo;
        }

        if (! self::configure()) {
            return self::$memo = ['etat' => self::INACTIF, 'claims' => null, 'expire_le' => null];
        }

        $etatLocal = self::lireEtatLocal();

        // Rafraîchir si demandé, jamais contacté, ou dernier contact trop vieux.
        $ttl = max(1, (int) config('hub_licence.ping_ttl_heures', 24)) * 3600;
        $perime = ($etatLocal['dernier_ping'] ?? 0) < (time() - $ttl);

        if ($forcerPing || $perime || blank($etatLocal['jeton'] ?? null)) {
            $etatLocal = self::ping() ?? $etatLocal;
        }

        return self::$memo = self::evaluer($etatLocal);
    }

    /** Le module est-il configuré pour cette instance ? */
    public static function configure(): bool
    {
        return (bool) config('hub_licence.actif', true)
            && filled(config('hub_licence.produit'))
            && filled(config('hub_licence.client_ref'))
            && (filled(config('hub_licence.secret')) || filled(config('hub_licence.instance_secret')));
    }

    /**
     * En-tête d'authentification du ping. Priorité au secret d'INSTANCE
     * (remis par le kit d'installation Docker : propre à UNE installation,
     * révocable en régénérant un kit) ; sinon le secret PRODUIT partagé,
     * voie historique de la flotte.
     */
    /**
     * CATALOGUE DE TARIFS À REFLÉTER AU HUB — POINT D'EXTENSION.
     *
     * La seule chose que ce paquet ne peut pas savoir : où chaque produit range
     * ses tarifs, et sous quelle forme. DigiClinic tient deux prix par gamme
     * dans `subscription_plans`, EduManager dans `plans` sur une connexion
     * dédiée, DigiCredit un prix et une période. Aucune abstraction commune
     * n'aurait de sens.
     *
     * L'application fournit donc une classe invocable via
     * `config('hub_licence.catalogue')`, exactement comme elle fournit son
     * inventaire de parc. Elle doit renvoyer une liste de :
     *
     *   ['code' => string, 'nom' => string, 'prix' => int,
     *    'periode' => 'mensuel'|'trimestriel'|'annuel',
     *    'limites' => ?array, 'actif' => bool]
     *
     * ON PUBLIE TOUJOURS ; C'EST LE HUB QUI ARBITRE. Ne pas déduire son rôle
     * du TYPE DE SECRET dont on dispose : c'est précisément ce qui a fait
     * taire deux serveurs centraux le 03/08/2026, chacun croyant à tort n'être
     * qu'un boîtier client. Le droit de publier est porté par le contrat côté
     * Hub (`publie_le_catalogue`), qui seul sait quel déploiement parle au nom
     * du produit.
     *
     * Filtrer sur la PROVENANCE de la donnée reste légitime — un boîtier edge
     * détient une réplique du catalogue de son central et n'a pas à la
     * republier. La distinction : « d'où vient cette donnée ? » se répond
     * localement, « ai-je le droit de parler ? » non.
     *
     * Best-effort strict : absence de classe, table absente, erreur → [] et le
     * ping continue. Un catalogue indisponible ne doit jamais coûter une
     * licence.
     */
    protected static function plansAnnonces(): array
    {
        $classe = config('hub_licence.catalogue');

        if (blank($classe) || ! class_exists($classe)) {
            return [];
        }

        try {
            $plans = app($classe)();

            if (! is_array($plans)) {
                return [];
            }

            // Filtrage minimal : une entrée incomplète serait refusée par le
            // Hub et ferait échouer la validation de TOUT le ping. On écarte
            // l'entrée fautive, jamais le message entier.
            return array_values(array_filter($plans, fn ($p): bool => is_array($p)
                && filled($p['code'] ?? null)
                && filled($p['nom'] ?? null)
                && is_numeric($p['prix'] ?? null)
                && in_array($p['periode'] ?? null, ['mensuel', 'trimestriel', 'annuel'], true)));
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : catalogue de tarifs indisponible', ['e' => $e->getMessage()]);

            return [];
        }
    }

    protected static function enteteAuthentification(): array
    {
        $instance = (string) config('hub_licence.instance_secret', '');

        return $instance !== ''
            ? ['X-Instance-Secret' => $instance]
            : ['X-Produit-Secret' => (string) config('hub_licence.secret')];
    }

    /**
     * Ping du Hub : rafraîchit jeton + clé + drapeau de révocation, et
     * rapporte version + utilisateurs actifs (supervision de flotte).
     * Retourne le nouvel état local, ou null si le réseau a échoué
     * (l'état existant reste alors en vigueur : tolérance aux coupures).
     */
    public static function ping(): ?array
    {
        $etatLocal = self::lireEtatLocal();

        try {
            $reponse = Http::timeout((int) config('hub_licence.timeout', 6))
                ->withHeaders(self::enteteAuthentification())
                ->acceptJson()
                ->post(rtrim((string) config('hub_licence.url'), '/') . '/api/v1/activation', array_filter([
                    'produit'             => config('hub_licence.produit'),
                    'client_ref'          => config('hub_licence.client_ref'),
                    'jti'                 => $etatLocal['jti'] ?? null,
                    'version'             => self::version(),
                    'utilisateurs_actifs' => self::utilisateursActifs(),
                    'sante'               => self::sante(),
                    // Inventaire du parc géré par CETTE instance : nombre
                    // d'institutions et de centres/sites. Sur un serveur
                    // central, c'est le décompte de tous ses tenants — le Hub
                    // suit ainsi ce que chaque central a créé.
                    'institutions_count'  => self::parc()['institutions'] ?? null,
                    'centres_count'       => self::parc()['centres'] ?? null,
                    // Catalogue de plans : la source de vérité est ICI, le Hub
                    // l'affiche en lecture. Sans cette remontée, les tarifs
                    // étaient saisis à la main au Hub et pouvaient différer de
                    // ce que l'application applique réellement.
                    // Ce qu'on a réellement mis en service parmi les clés
                    // reçues — voir clesAppliquees(). Vide reste vide : « je
                    // n'ai rien appliqué » est une information, pas un silence.
                    'cles_appliquees'     => self::clesAppliquees(),
                    'plans'               => self::plansAnnonces() ?: null,
                    // On ne réclame des ordres qu'en console (ping planifié).
                    // Un ping déclenché au fil d'une requête d'utilisateur ne
                    // doit pas vider les caches ni lancer une sauvegarde au
                    // milieu d'une consultation.
                    'avec_ordres'         => self::peutExecuterDesOrdres(),
                    // Comptes rendus qu'un ping précédent n'a pas réussi à
                    // remonter (réseau coupé au mauvais moment) : ils voyagent
                    // avec le ping suivant plutôt que d'être perdus.
                    'ordres_executes'     => self::comptesRendusEnAttente() ?: null,
                ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            // Réseau indisponible : on garde l'état local (jeton en cache).
            $etatLocal['derniere_tentative'] = time();
            self::ecrireEtatLocal($etatLocal);

            return null;
        }

        // 401 (secret faux) / 404 (client inconnu) : réponses DÉFINITIVES du
        // Hub — on les mémorise, mais on ne détruit pas un jeton encore valide
        // pour une erreur de configuration passagère.
        if ($reponse->status() === 404) {
            $etatLocal['statut_abonnement'] = 'inconnu';
            $etatLocal['dernier_ping'] = time();
            self::ecrireEtatLocal($etatLocal);

            return $etatLocal;
        }

        if (! $reponse->ok()) {
            Log::warning('LicenceHub : ping refusé', ['statut' => $reponse->status()]);
            $etatLocal['derniere_tentative'] = time();
            self::ecrireEtatLocal($etatLocal);

            return $etatLocal;
        }

        $corps = $reponse->json();

        $etatLocal = [
            'jeton'             => $corps['jeton'] ?? null,
            'jti'               => $corps['jti'] ?? null,
            // Clé épinglée par l'env prioritaire ; sinon on garde celle déjà
            // mémorisée, sinon celle reçue (trust-on-first-use).
            'cle_publique'      => $etatLocal['cle_publique'] ?? ($corps['cle_publique'] ?? null),
            'revoquee'          => (bool) ($corps['revoquee'] ?? false),
            'statut_abonnement' => $corps['statut_abonnement'] ?? null,
            'dernier_ping'      => time(),
            // Clés de service distribuées par le Hub (saisies UNE fois là-bas,
            // au lieu d'une recopie de .env en .env qui a coûté trois pannes en
            // deux jours). Un TABLEAU — même vide — fait autorité : c'est ainsi
            // qu'une clé désactivée au Hub disparaît d'ici. Absent ou null
            // (vieux Hub, lecture en échec là-bas) : on GARDE les clés déjà
            // mémorisées — les effacer sur un incident passager recréerait la
            // panne que ce mécanisme supprime. Chiffrées au repos avec
            // l'APP_KEY : le fichier d'état ne porte jamais un secret en clair.
            'cles_service'      => is_array($corps['cles_service'] ?? null)
                ? self::chiffrerCles($corps['cles_service'])
                : ($etatLocal['cles_service'] ?? null),
        ];

        // Le ping a emporté les comptes rendus en attente : le Hub les a reçus.
        self::ecrireEtatLocal($etatLocal);

        // Ordres reçus : les exécuter et rendre compte tout de suite.
        // Isolé du reste : un ordre qui tourne mal ne doit pas compromettre le
        // rafraîchissement de licence qui vient d'aboutir.
        try {
            self::traiterOrdres(is_array($corps['ordres'] ?? null) ? $corps['ordres'] : []);
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : traitement des ordres interrompu', ['e' => $e->getMessage()]);
        }

        return $etatLocal;
    }

    // ── Ordres reçus du Hub ──────────────────────────────────────────────

    /**
     * Cette instance est-elle en situation d'exécuter un ordre ?
     *
     * Uniquement en console : c'est le ping planifié (`hub:ping`) qui porte les
     * ordres. Le ping opportuniste déclenché par le middleware, lui, se produit
     * au milieu de la requête d'un utilisateur — on n'y vide pas les caches.
     */
    protected static function peutExecuterDesOrdres(): bool
    {
        return app()->runningInConsole();
    }

    /**
     * Exécute les ordres reçus, puis rend compte au Hub.
     *
     * Le compte rendu part immédiatement (second appel au même endpoint) pour
     * que l'écran du Hub bouge en quelques secondes ; s'il n'y arrive pas, il
     * est mis de côté et repartira avec le ping suivant.
     */
    protected static function traiterOrdres(array $ordres): void
    {
        if ($ordres === [] || ! self::peutExecuterDesOrdres()) {
            return;
        }

        $comptesRendus = [];

        foreach ($ordres as $ordre) {
            $reference = (string) ($ordre['reference'] ?? '');
            $action    = (string) ($ordre['action'] ?? '');

            if ($reference === '') {
                continue;
            }

            if (! in_array($action, self::ACTIONS_CONNUES, true)) {
                // Refus explicite plutôt que silence : le Hub doit voir que
                // cette instance ne connaît pas cet ordre (version plus
                // ancienne, ou tentative d'injection).
                Log::warning('LicenceHub : ordre refusé, action inconnue', [
                    'reference' => $reference,
                    'action'    => $action,
                ]);
                $comptesRendus[] = [
                    'reference' => $reference,
                    'verdict'   => 'echouee',
                    'message'   => "Ordre « {$action} » inconnu de cette instance : refusé.",
                ];

                continue;
            }

            try {
                $resultat = self::executerOrdre($action, (array) ($ordre['parametres'] ?? []));
            } catch (\Throwable $e) {
                $resultat = ['verdict' => 'echouee', 'message' => $e->getMessage()];
            }

            $comptesRendus[] = [
                'reference' => $reference,
                'verdict'   => $resultat['verdict'],
                'message'   => mb_substr((string) $resultat['message'], 0, 500),
            ];
        }

        if ($comptesRendus === []) {
            return;
        }

        if (! self::envoyerComptesRendus($comptesRendus)) {
            self::mettreDeCoteComptesRendus($comptesRendus);
        }
    }

    /**
     * Exécution d'un ordre. Les quatre actions du contrat sont génériques ;
     * « sauvegarder » délègue à la procédure propre à l'application, déclarée
     * dans hub_licence.sauvegarde — chaque produit sauvegarde à sa façon.
     */
    protected static function executerOrdre(string $action, array $parametres): array
    {
        switch ($action) {
            case 'rafraichir-licence':
                // Le ping qui vient de nous apporter cet ordre A DÉJÀ rafraîchi
                // la licence : il n'y a rien de plus à faire.
                $etat = self::lireEtatLocal();

                return [
                    'verdict' => 'reussie',
                    'message' => 'Licence rafraîchie (statut : ' . ($etat['statut_abonnement'] ?? 'inconnu') . ').',
                ];

            case 'vider-caches':
                Artisan::call('optimize:clear');

                return ['verdict' => 'reussie', 'message' => 'Caches applicatifs purgés.'];

            case 'diagnostic':
                $sante  = self::sante() ?? [];
                $detail = ['version' => self::version(), 'php' => PHP_VERSION] + $sante;

                return [
                    'verdict' => 'reussie',
                    'message' => collect($detail)
                        ->map(fn ($v, $k): string => $k . '=' . (is_scalar($v) ? $v : '?'))
                        ->implode(' · '),
                ];

            case 'sauvegarder':
                $procedure = config('hub_licence.sauvegarde');

                if (blank($procedure)) {
                    return [
                        'verdict' => 'echouee',
                        'message' => 'Aucune procédure de sauvegarde déclarée (hub_licence.sauvegarde).',
                    ];
                }

                // Soit un nom de commande artisan, soit une classe invocable.
                if (is_string($procedure) && class_exists($procedure)) {
                    $retour = app($procedure)($parametres);

                    return ['verdict' => 'reussie', 'message' => is_string($retour) ? $retour : 'Sauvegarde effectuée.'];
                }

                $code = Artisan::call((string) $procedure);

                return [
                    'verdict' => $code === 0 ? 'reussie' : 'echouee',
                    'message' => trim(Artisan::output()) ?: ('Code de sortie ' . $code),
                ];
        }

        return ['verdict' => 'echouee', 'message' => 'Action non implémentée.'];
    }

    /** Renvoie les comptes rendus au Hub. false si le réseau n'a pas suivi. */
    protected static function envoyerComptesRendus(array $comptesRendus): bool
    {
        try {
            $reponse = Http::timeout((int) config('hub_licence.timeout', 6))
                ->withHeaders(self::enteteAuthentification())
                ->acceptJson()
                ->post(rtrim((string) config('hub_licence.url'), '/') . '/api/v1/activation', [
                    'produit'         => config('hub_licence.produit'),
                    'client_ref'      => config('hub_licence.client_ref'),
                    'ordres_executes' => $comptesRendus,
                ]);

            return $reponse->ok();
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : comptes rendus non remontés', ['e' => $e->getMessage()]);

            return false;
        }
    }

    /** Comptes rendus en souffrance, à emporter au prochain ping. */
    protected static function comptesRendusEnAttente(): array
    {
        $etat = self::lireEtatLocal();

        return is_array($etat['ordres_executes'] ?? null)
            ? array_slice($etat['ordres_executes'], 0, 20)
            : [];
    }

    protected static function mettreDeCoteComptesRendus(array $comptesRendus): void
    {
        $etat = self::lireEtatLocal();
        // Borné à 20 : la file du Hub n'en accepte pas plus, et un compte rendu
        // vieux de plusieurs jours n'intéresse plus personne.
        $etat['ordres_executes'] = array_slice(
            array_merge($etat['ordres_executes'] ?? [], $comptesRendus),
            -20,
        );
        self::ecrireEtatLocal($etat);
    }

    // ── Évaluation hors-ligne ────────────────────────────────────────────

    /** Combine drapeau de révocation + vérification RS256 + fenêtre exp/grâce. */
    protected static function evaluer(array $etatLocal): array
    {
        if (! empty($etatLocal['revoquee'])) {
            return ['etat' => self::REVOQUE, 'claims' => null, 'expire_le' => null];
        }

        $jeton = $etatLocal['jeton'] ?? null;
        // Clé publique : PEM direct, sinon variante base64 (une seule ligne de
        // .env — c'est ce que livre le kit d'installation Docker), sinon la
        // clé épinglée au premier ping (TOFU).
        $cle = config('hub_licence.cle_publique')
            ?: (filled(config('hub_licence.cle_publique_b64'))
                ? (base64_decode((string) config('hub_licence.cle_publique_b64'), true) ?: null)
                : null)
            ?: ($etatLocal['cle_publique'] ?? null);

        if (blank($jeton) || blank($cle)) {
            return self::verdictSansLicence($etatLocal);
        }

        $claims = self::verifierSignature($jeton, $cle);

        if ($claims === null) {
            return ['etat' => self::INVALIDE, 'claims' => null, 'expire_le' => null];
        }

        $exp      = (int) ($claims['exp'] ?? 0);
        $graceSec = (int) ($claims['grace'] ?? 0) * 86400;
        $now      = time();

        $etat = match (true) {
            $now < $exp             => self::ACTIF,
            $now < $exp + $graceSec => self::GRACE,
            default                 => self::SUSPENDU,
        };

        return ['etat' => $etat, 'claims' => $claims, 'expire_le' => $exp];
    }

    /**
     * Aucune licence en main. On distingue deux situations très différentes :
     *
     *  - installation TOUTE FRAÎCHE dont l'activation n'a pas encore abouti
     *    (Hub momentanément injoignable, clés à générer, kit posé un peu tôt) :
     *    bloquer là serait couper une clinique le jour de sa mise en route, à
     *    cause du réseau. On laisse passer pendant `delai_activation_heures`
     *    (72 h par défaut = durée de vie d'un jeton d'installation) ;
     *  - au-delà de ce délai : blocage franc, l'installation n'a jamais réussi
     *    à s'activer et cela ne relève plus de l'incident passager.
     *
     * Une licence RÉVOQUÉE n'arrive jamais ici : elle est traitée avant, et ne
     * bénéficie donc d'aucune tolérance — le kill-switch reste immédiat.
     *
     * La date de première vue est posée une seule fois, au premier passage.
     */
    protected static function verdictSansLicence(array $etatLocal): array
    {
        $delai = max(0, (int) config('hub_licence.delai_activation_heures', 72)) * 3600;

        if ($delai === 0) {
            return ['etat' => self::SANS_LICENCE, 'claims' => null, 'expire_le' => null];
        }

        $premiereVue = (int) ($etatLocal['premiere_vue'] ?? 0);

        if ($premiereVue === 0) {
            $premiereVue = time();
            $etatLocal['premiere_vue'] = $premiereVue;
            self::ecrireEtatLocal($etatLocal);
        }

        $etat = time() < $premiereVue + $delai ? self::ATTENTE_ACTIVATION : self::SANS_LICENCE;

        return ['etat' => $etat, 'claims' => null, 'expire_le' => null, 'premiere_vue' => $premiereVue];
    }

    /** Vérification RS256 « miroir » de celle du Hub (mêmes primitives). */
    public static function verifierSignature(string $jwt, string $publicKeyPem): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$h, $p, $s] = $parts;
        $signature = base64_decode(strtr($s, '-_', '+/'));

        if (openssl_verify("$h.$p", $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

        return is_array($claims) ? $claims : null;
    }

    // ── État local & télémétrie ──────────────────────────────────────────

    // ── Clés de service distribuées par le Hub ───────────────────────────

    /**
     * Une clé de service, ou null si le Hub n'en a pas distribué de ce nom.
     *
     * L'ENV LOCAL PRIME TOUJOURS : une clé posée dans le .env d'une machine
     * est un choix délibéré de l'exploitant, le Hub ne l'écrase pas. Ce
     * mécanisme comble les trous — machine jamais configurée, déménagement —
     * il ne confisque pas la main.
     */
    public static function cleService(string $nom): ?string
    {
        $env = env($nom);
        if (filled($env)) {
            return (string) $env;
        }

        try {
            $chiffre = self::lireEtatLocal()['cles_service'] ?? null;
            if (! is_string($chiffre) || $chiffre === '') {
                return null;
            }

            $cles = json_decode(
                \Illuminate\Support\Facades\Crypt::decryptString($chiffre),
                true
            );

            $valeur = is_array($cles) ? ($cles[$nom] ?? null) : null;

            return filled($valeur) ? (string) $valeur : null;
        } catch (\Throwable $e) {
            // APP_KEY changée, fichier corrompu : une clé de service absente
            // dégrade une fonctionnalité, jamais l'application.
            Log::warning('LicenceHub : clés de service illisibles', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Complète la configuration avec les clés du Hub, SANS écraser une valeur
     * déjà présente. À appeler au boot :
     *
     *   LicenceHub::appliquerClesService(['services.groq.key' => 'GROQ_API_KEY']);
     */
    public static function appliquerClesService(array $correspondances): void
    {
        foreach ($correspondances as $cheminConfig => $nomCle) {
            try {
                if (filled(config($cheminConfig))) {
                    continue; // l'installation a sa propre valeur : on respecte
                }

                $valeur = self::cleService($nomCle);
                if ($valeur !== null) {
                    config([$cheminConfig => $valeur]);
                    self::$clesAppliquees[] = $nomCle;
                }
            } catch (\Throwable $e) {
                Log::warning('LicenceHub : application des clés de service', ['e' => $e->getMessage()]);
            }
        }
    }

    /**
     * Clés du Hub réellement mises en service par CE processus.
     *
     * Le Hub sait ce qu'il envoie, jamais ce qu'on en fait : il a livré
     * GROQ_API_KEY pendant des heures le 03/08 sans que rien ne l'applique
     * ici, et personne, d'aucun côté, ne pouvait le voir. Le ping le lui dit
     * désormais — c'est ce qui distingue « livrée » de « appliquée » sur son
     * écran de distribution.
     *
     * Renvoyé au ping, donc en CONSOLE : ce que la commande planifiée a
     * appliqué à son propre démarrage. Une clé ignorée parce que la machine a
     * déjà la sienne n'y figure pas — elle n'est pas « appliquée », et le
     * prétendre masquerait qu'une valeur locale prend le pas.
     *
     * @var array<int, string>
     */
    protected static array $clesAppliquees = [];

    /** @return array<int, string> */
    public static function clesAppliquees(): array
    {
        return array_values(array_unique(self::$clesAppliquees));
    }

    /** Chiffre le paquet de clés reçu, pour le fichier d'état local. */
    protected static function chiffrerCles(array $cles): ?string
    {
        try {
            return \Illuminate\Support\Facades\Crypt::encryptString(
                json_encode($cles, JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : chiffrement des clés de service impossible', ['e' => $e->getMessage()]);

            return null;
        }
    }

    protected static function lireEtatLocal(): array
    {
        $fichier = (string) config('hub_licence.fichier_etat');

        try {
            if (is_file($fichier)) {
                $donnees = json_decode((string) file_get_contents($fichier), true);

                return is_array($donnees) ? $donnees : [];
            }
        } catch (\Throwable $e) {
            // Fichier illisible : repartir de zéro, en le traçant.
            // Log::warning et pas un helper maison : ce fichier est copié tel
            // quel dans cinq applications, il ne peut dépendre que du framework.
            Log::warning('LicenceHub : état local illisible', ['e' => $e->getMessage()]);
        }

        return [];
    }

    protected static function ecrireEtatLocal(array $etat): void
    {
        try {
            $fichier = (string) config('hub_licence.fichier_etat');
            @mkdir(dirname($fichier), 0770, true);
            file_put_contents($fichier, json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : état local non persistable', ['e' => $e->getMessage()]);
        }
    }

    protected static function version(): ?string
    {
        $version = config('hub_licence.version');

        if (filled($version)) {
            return (string) $version;
        }

        $fichier = base_path('VERSION');

        return is_file($fichier) ? trim((string) file_get_contents($fichier)) : null;
    }

    /**
     * Agrégats de santé rapportés au Hub. Bornés à 12 clés NUMÉRIQUES : c'est
     * la limite qu'accepte le Hub, et surtout le rappel que ce canal transporte
     * des compteurs, jamais de la donnée métier.
     *
     * La sonde ne doit JAMAIS faire échouer un ping : une erreur ici rendrait
     * l'instance incapable de rafraîchir sa licence, pour une simple métrique.
     */
    protected static function sante(): ?array
    {
        $classe = config('hub_licence.sonde_sante');

        if (blank($classe) || ! class_exists($classe)) {
            return null;
        }

        try {
            $valeurs = app($classe)();

            if (! is_array($valeurs) || $valeurs === []) {
                return null;
            }

            $propre = [];

            foreach ($valeurs as $cle => $valeur) {
                if (is_numeric($valeur)) {
                    $propre[(string) $cle] = 0 + $valeur;
                }
            }

            return $propre === [] ? null : array_slice($propre, 0, 12, true);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function utilisateursActifs(): ?int
    {
        $classe = config('hub_licence.compteur_utilisateurs');

        if (blank($classe) || ! class_exists($classe)) {
            return null;
        }

        try {
            $n = app($classe)();

            return is_numeric($n) ? max(0, (int) $n) : null;
        } catch (\Throwable $e) {
            // La supervision ne doit jamais faire échouer un ping.
            Log::warning('LicenceHub : comptage d\'utilisateurs indisponible', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Inventaire du parc de CETTE instance : ['institutions' => ?int,
     * 'centres' => ?int]. Fourni par la classe hub_licence.inventaire (propre à
     * chaque produit), tolérante : une clé absente = non rapportée, jamais une
     * exception. Mémoïsé par requête (le ping le lit deux fois).
     */
    protected static function parc(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $classe = config('hub_licence.inventaire');
        if (blank($classe) || ! class_exists($classe)) {
            return $memo = [];
        }

        try {
            $r = app($classe)();
            $normaliser = fn ($v) => is_numeric($v) ? max(0, (int) $v) : null;

            return $memo = [
                'institutions' => $normaliser($r['institutions'] ?? null),
                'centres'      => $normaliser($r['centres'] ?? null),
            ];
        } catch (\Throwable $e) {
            Log::warning('LicenceHub : inventaire du parc indisponible', ['e' => $e->getMessage()]);

            return $memo = [];
        }
    }
}
