<?php

namespace RichardHulbert\Revisions\Tests;

use RichardHulbert\Revisions\Tests\Fixtures\Post;

/**
 * Pins the model events new() and update() fire, and the state listeners see.
 *
 * new() writes the row, then sets prime once the id exists. Listeners must
 * only ever see the finished row: a listener reading ->prime on the first
 * write once recursed through a relation of the same name, and would still
 * silently read null.
 */
class RevisionEventsTest extends TestCase
{
    private const EVENTS = ['saving', 'creating', 'created', 'updating', 'updated', 'saved'];

    /** @var array<int, array{event: string, id: mixed, prime: mixed}> */
    private array $fired = [];

    private function recordPostEvents(): void
    {
        foreach (self::EVENTS as $event) {
            Post::registerModelEvent($event, function (Post $post) use ($event) {
                $this->fired[] = [
                    'event' => $event,
                    'id' => $post->id,
                    // how listeners read it, through __get, not the raw attribute bag
                    'prime' => $post->prime,
                ];
            });
        }
    }

    private function firedEvents(): array
    {
        return array_column($this->fired, 'event');
    }

    public function test_listeners_on_created_and_saved_see_prime_equal_to_id(): void
    {
        $this->recordPostEvents();

        $post = Post::new(['title' => 'First']);

        $afterInsert = array_filter($this->fired, fn (array $fired) => in_array($fired['event'], ['created', 'saved']));
        $this->assertNotEmpty($afterInsert);
        foreach ($afterInsert as $fired) {
            $this->assertSame($post->id, $fired['id'], "{$fired['event']} saw the wrong row");
            $this->assertSame($post->id, $fired['prime'], "{$fired['event']} saw a row without its prime");
        }
    }

    /**
     * create() followed by save() fired saved twice per new() - so Scout, with
     * a driver configured, indexed (or removed) every new revision twice.
     * Anything that fires model events on the half-written row belongs to
     * that old shape and must fail here.
     */
    public function test_new_fires_created_and_saved_exactly_once_each(): void
    {
        $this->recordPostEvents();

        Post::new(['title' => 'First']);

        $this->assertSame(['created', 'saved'], $this->firedEvents());
    }

    public function test_new_returns_a_persisted_recently_created_model(): void
    {
        $post = Post::new(['title' => 'First']);

        $this->assertTrue($post->exists);
        $this->assertTrue($post->wasRecentlyCreated);
        $this->assertFalse($post->isDirty());
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'prime' => $post->id]);
    }

    /**
     * Events fired with halting on stop at the first listener returning
     * anything non-null. new() fires them without halting, as Eloquent does.
     */
    public function test_a_listener_returning_a_value_does_not_stop_later_listeners(): void
    {
        Post::saved(fn () => 'a value');
        Post::created(fn () => 'a value');
        $this->recordPostEvents();

        Post::new(['title' => 'First']);

        $this->assertSame(['created', 'saved'], $this->firedEvents());
    }

    public function test_update_still_fires_the_full_sequence_on_a_row_carrying_the_prime(): void
    {
        $this->actingAsUserOnBranch(2);
        $post = Post::new(['title' => 'First']);
        $this->recordPostEvents();

        $revision = $post->update(['title' => 'Second']);

        $this->assertSame(['saving', 'creating', 'created', 'saved'], $this->firedEvents());
        foreach ($this->fired as $fired) {
            // replicate() carries the prime, so it is there before the insert too
            $this->assertSame($post->id, $fired['prime'], "{$fired['event']} saw a row without its prime");
        }
        $this->assertSame($revision->id, $this->fired[3]['id']);
        $this->assertTrue($revision->wasRecentlyCreated);
    }
}
