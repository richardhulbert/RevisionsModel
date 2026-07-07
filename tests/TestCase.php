<?php

namespace RichardHulbert\Revisions\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RichardHulbert\Revisions\RevisionsServiceProvider;
use RichardHulbert\Revisions\Tests\Fixtures\Branch;
use RichardHulbert\Revisions\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RevisionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('revisions.branch_model', Branch::class);
        $app['config']->set('revisions.user_model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('branch')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        // both revision tables use the package's Blueprint macro
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->revisions();
            $table->string('title');
            $table->bigInteger('template_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->revisions();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Branch::create(['name' => 'public']);   // id 1 - the public branch
        Branch::create(['name' => 'draft']);    // id 2
    }

    protected function actingAsUserOnBranch(int $branch = 2): User
    {
        $user = User::create(['name' => 'Richard', 'branch' => $branch]);
        $this->actingAs($user);

        return $user;
    }
}
