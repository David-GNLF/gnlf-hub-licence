<?php

namespace Gnlf\HubLicence;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * La charte graphique GNLF, tirée du Hub.
 *
 * Le Hub publie une version (jetons CSS/JSON, logos SVG, favicons) ; chaque
 * application vient la chercher avec `GET /api/v1/charte`, écrit les fichiers
 * dans son dossier public, puis accuse réception. Modèle PULL, comme le ping :
 * le Hub ne joint jamais l'application.
 *
 *   php artisan charte:synchroniser          (au démarrage du conteneur,
 *                                             puis une fois par jour)
 *
 * Les fichiers arrivent dans `hub_licence.charte.dossier` (par défaut
 * public/charte) : /charte/jetons/gnlf-tokens.css, /charte/logos/svg/…
 *
 * Sûreté :
 *  - jamais bloquant : une erreur réseau ou d'écriture laisse les fichiers
 *    en place et se résume dans le statut rendu ;
 *  - aucun chemin reçu n'est écrit tel quel : seuls jetons/, logos/svg/ et
 *    logos/png/ sont acceptés, avec des noms de fichiers simples ;
 *  - écriture par fichier temporaire puis renommage : jamais de fichier
 *    à moitié écrit servi aux navigateurs.
 */
class CharteGnlf
{
    public const NON_CONFIGURE = 'non_configure';
    public const REFUSE = 'refuse';
    public const AUCUNE_VERSION = 'aucune_version';
    public const INJOIGNABLE = 'injoignable';
    public const A_JOUR = 'a_jour';
    public const MIS_A_JOUR = 'mis_a_jour';
    public const ERREUR = 'erreur';

    /** Chemins acceptés dans une version reçue. */
    private const CHEMIN_PERMIS = '~^(jetons|logos/svg|logos/png)/[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.(css|json|svg|png|ico)$~';

    /** Codes produit du Hub dont le nom d'application diffère. */
    private const APPLICATIONS = ['creditmanager' => 'digicredit'];

    /**
     * Tire la version publiée et l'écrit si elle a changé.
     *
     * @return array{statut: string, numero?: ?string, fichiers?: int, message?: string}
     */
    public static function synchroniser(bool $forcer = false): array
    {
        $application = self::application();
        $entetes = self::entetes();
        if (! $application || $entetes === [] || ! filled(config('hub_licence.url'))) {
            return ['statut' => self::NON_CONFIGURE, 'message' => 'Hub, produit ou secret non renseigné : rien à faire.'];
        }

        $etat = self::etatLocal();
        $url = rtrim((string) config('hub_licence.url'), '/') . '/api/v1/charte';

        try {
            $reponse = Http::timeout((int) config('hub_licence.charte.timeout', 20))
                ->withHeaders($entetes + ['X-Application' => $application])
                ->acceptJson()
                ->get($url, ! $forcer && filled($etat['empreinte'] ?? null) ? ['depuis' => $etat['empreinte']] : []);
        } catch (\Throwable $e) {
            return ['statut' => self::INJOIGNABLE, 'message' => $e->getMessage()];
        }

        if ($reponse->status() === 401) {
            return ['statut' => self::REFUSE, 'message' => 'Le Hub refuse la clé de cette application.'];
        }
        if ($reponse->status() === 404) {
            return ['statut' => self::AUCUNE_VERSION, 'message' => 'Aucune charte publiée sur le Hub.'];
        }
        if (! $reponse->successful()) {
            return ['statut' => self::INJOIGNABLE, 'message' => 'Réponse HTTP ' . $reponse->status() . '.'];
        }

        $numero = (string) $reponse->json('numero');
        $empreinte = (string) $reponse->json('empreinte');

        if ($reponse->json('a_jour') === true) {
            self::accuser($numero, $empreinte, $entetes, $application);

            return ['statut' => self::A_JOUR, 'numero' => $numero];
        }

        $fichiers = $reponse->json('fichiers');
        if (! is_array($fichiers) || $fichiers === []) {
            return ['statut' => self::ERREUR, 'message' => 'Version reçue sans fichiers.'];
        }

        try {
            $ecrits = self::ecrire($fichiers);
        } catch (\Throwable $e) {
            Log::warning('[charte] écriture impossible : ' . $e->getMessage());

            return ['statut' => self::ERREUR, 'message' => $e->getMessage()];
        }

        self::ecrireEtatLocal(['numero' => $numero, 'empreinte' => $empreinte, 'le' => now()->toIso8601String()]);
        self::accuser($numero, $empreinte, $entetes, $application);

        return ['statut' => self::MIS_A_JOUR, 'numero' => $numero, 'fichiers' => $ecrits];
    }

    /** Le dossier où arrivent les fichiers (public/charte par défaut). */
    public static function dossier(): string
    {
        return rtrim((string) (config('hub_licence.charte.dossier') ?: public_path('charte')), '/\\');
    }

    /** La version écrite localement, ou null. */
    public static function versionLocale(): ?string
    {
        return self::etatLocal()['numero'] ?? null;
    }

    /** Nom de l'application pour le Hub : la config, sinon le code produit. */
    protected static function application(): ?string
    {
        $app = config('hub_licence.charte.application') ?: config('hub_licence.produit');
        if (! is_string($app) || $app === '') {
            return null;
        }

        return self::APPLICATIONS[$app] ?? $app;
    }

    /**
     * Authentification, dans l'ordre : clé dédiée à la charte, secret
     * d'instance (kit), secret produit — comme le ping pour les deux derniers.
     */
    protected static function entetes(): array
    {
        $cle = (string) config('hub_licence.charte.cle', '');
        if ($cle !== '') {
            return ['X-Charte-Cle' => $cle];
        }
        $instance = (string) config('hub_licence.instance_secret', '');
        if ($instance !== '') {
            return ['X-Instance-Secret' => $instance];
        }
        $secret = (string) config('hub_licence.secret', '');

        return $secret !== '' ? ['X-Produit-Secret' => $secret] : [];
    }

    /**
     * Écrit les fichiers reçus ; retire de jetons/ et logos/ ceux que la
     * version ne contient plus.
     *
     * @param  array<string, string>  $fichiers  chemin relatif => base64
     */
    protected static function ecrire(array $fichiers): int
    {
        $racine = self::dossier();
        $retenus = [];
        foreach ($fichiers as $chemin => $base64) {
            if (! is_string($chemin) || ! preg_match(self::CHEMIN_PERMIS, $chemin) || ! is_string($base64)) {
                throw new \RuntimeException("Chemin refusé dans la version reçue : {$chemin}");
            }
            $octets = base64_decode($base64, true);
            if ($octets === false) {
                throw new \RuntimeException("Contenu illisible pour {$chemin}");
            }
            $retenus[$chemin] = $octets;
        }

        foreach ($retenus as $chemin => $octets) {
            $cible = $racine . '/' . $chemin;
            if (! is_dir(dirname($cible)) && ! mkdir(dirname($cible), 0775, true) && ! is_dir(dirname($cible))) {
                throw new \RuntimeException('Dossier impossible à créer : ' . dirname($cible));
            }
            $temp = $cible . '.tmp-' . getmypid();
            if (file_put_contents($temp, $octets) === false || ! rename($temp, $cible)) {
                @unlink($temp);
                throw new \RuntimeException("Écriture impossible : {$cible}");
            }
        }

        foreach (['jetons', 'logos/svg', 'logos/png'] as $sous) {
            foreach (glob($racine . '/' . $sous . '/*') ?: [] as $existant) {
                $relatif = $sous . '/' . basename($existant);
                if (is_file($existant) && ! isset($retenus[$relatif])) {
                    @unlink($existant);
                }
            }
        }

        return count($retenus);
    }

    protected static function accuser(string $numero, string $empreinte, array $entetes, string $application): void
    {
        try {
            Http::timeout((int) config('hub_licence.timeout', 6))
                ->withHeaders($entetes + ['X-Application' => $application])
                ->acceptJson()
                ->post(rtrim((string) config('hub_licence.url'), '/') . '/api/v1/charte/accuse', [
                    'numero' => $numero,
                    'empreinte' => $empreinte,
                ]);
        } catch (\Throwable) {
            // L'accusé n'est qu'une information pour le Hub : il repassera demain.
        }
    }

    protected static function fichierEtat(): string
    {
        return (string) (config('hub_licence.charte.fichier_etat') ?: storage_path('app/charte_gnlf.json'));
    }

    protected static function etatLocal(): array
    {
        $json = @file_get_contents(self::fichierEtat());

        return $json ? (json_decode($json, true) ?: []) : [];
    }

    protected static function ecrireEtatLocal(array $etat): void
    {
        @file_put_contents(self::fichierEtat(), json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
