<?php

namespace RichardHulbert\Revisions;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class RevisionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/revisions.php', 'revisions');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/revisions.php' => config_path('revisions.php'),
        ], 'revisions-config');

        // $table->revisions() - the three columns every RevisionsModel table needs
        Blueprint::macro('revisions', function () {
            /** @var Blueprint $this */
            $this->bigInteger('prime')->default(1)->index();
            $this->integer('user_id')->default(1);
            $this->integer('branch_id')->default(1);

            return $this;
        });
    }
}
