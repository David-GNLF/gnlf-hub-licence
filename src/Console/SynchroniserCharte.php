<?php

namespace Gnlf\HubLicence\Console;

use Gnlf\HubLicence\CharteGnlf;
use Illuminate\Console\Command;

/**
 * php artisan charte:synchroniser [--forcer]
 *
 * Tire la charte GNLF publiée par le Hub et l'écrit dans public/charte.
 * Rend TOUJOURS 0 : au démarrage d'un conteneur, une charte injoignable ne doit
 * jamais empêcher l'application de démarrer — les fichiers déjà en place
 * restent servis. Le statut est écrit sur la sortie et dans le journal.
 */
class SynchroniserCharte extends Command
{
    protected $signature = 'charte:synchroniser {--forcer : retélécharger même si la version locale est à jour}';

    protected $description = 'Récupère la charte graphique GNLF publiée par le Hub (jetons, logos).';

    public function handle(): int
    {
        $r = CharteGnlf::synchroniser((bool) $this->option('forcer'));

        $ligne = match ($r['statut']) {
            CharteGnlf::MIS_A_JOUR => "Charte v{$r['numero']} installée ({$r['fichiers']} fichiers) dans " . CharteGnlf::dossier() . '.',
            CharteGnlf::A_JOUR => "Charte v{$r['numero']} déjà à jour.",
            default => 'Charte non synchronisée (' . $r['statut'] . ') : ' . ($r['message'] ?? ''),
        };

        in_array($r['statut'], [CharteGnlf::MIS_A_JOUR, CharteGnlf::A_JOUR, CharteGnlf::NON_CONFIGURE], true)
            ? $this->info($ligne)
            : $this->warn($ligne);

        return self::SUCCESS;
    }
}
