<?php

namespace RichardHulbert\Revisions\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use RichardHulbert\Revisions\RevisionsModel;
use RichardHulbert\Revisions\Tests\Fixtures\Post;
use RichardHulbert\Revisions\Tests\Fixtures\Template;

/**
 * Pins a boundary rather than a behaviour: no public method on a
 * RevisionsModel may share a name with a column of its table.
 *
 * When a column is missing from the attribute bag - as `prime` is inside
 * new() until it is assigned - getAttribute() falls through to relationship
 * resolution and calls any method of that name. A relation that reads the
 * missing column resolves itself again, forever; any other method throws.
 * Methods declared on Model itself are skipped by getAttribute(), so only
 * methods added by RevisionsModel or a subclass are dangerous.
 */
class ColumnCollisionTest extends TestCase
{
    public function test_no_public_method_shares_a_name_with_a_column(): void
    {
        $subclasses = self::revisionsModelsIn(__DIR__.'/Fixtures', __NAMESPACE__.'\\Fixtures');

        // guard against a vacuous pass if discovery ever finds nothing
        $this->assertContains(Post::class, $subclasses);
        $this->assertContains(Template::class, $subclasses);

        $collisions = [];
        foreach ($subclasses as $class) {
            if ($found = self::collisions($class)) {
                $collisions[$class] = $found;
            }
        }

        $this->assertSame([], $collisions, 'Public methods named like a column of their table');
    }

    /**
     * Names of public methods on $class, not declared on Model, that match a
     * column of its table.
     *
     * @param  class-string<RevisionsModel>  $class
     * @return string[]
     */
    public static function collisions(string $class): array
    {
        $columns = array_map('strtolower', Schema::getColumnListing((new $class)->getTable()));
        $methods = array_diff(self::publicMethods($class), self::publicMethods(Model::class));

        return array_values(array_intersect($methods, $columns));
    }

    /**
     * @return string[] lower-cased, as PHP method names are case-insensitive
     */
    private static function publicMethods(string $class): array
    {
        return array_map(
            fn (ReflectionMethod $method) => strtolower($method->getName()),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );
    }

    /**
     * @return class-string<RevisionsModel>[]
     */
    private static function revisionsModelsIn(string $directory, string $namespace): array
    {
        $classes = array_map(
            fn (string $file) => $namespace.'\\'.basename($file, '.php'),
            glob($directory.'/*.php')
        );

        return array_values(array_filter($classes, fn (string $class) => is_subclass_of($class, RevisionsModel::class)));
    }
}
