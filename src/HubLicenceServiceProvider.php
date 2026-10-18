<?php

namespace Gnlf\HubLicence;

use Illuminate\Support\ServiceProvider;

/**
 * Découvert automatiquement par Laravel (extra.laravel.providers) : déclare
 * les commandes du paquet. Rien d'autre — le client d'activation reste une
 * classe statique que l'application étend.
 */
class HubLicenceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SynchroniserCharte::class]);
        }
    }
}
