<?php

namespace RichardHulbert\Revisions\Tests;

use RichardHulbert\Revisions\Tests\Fixtures\Post;

/**
 * Pins the model events new(), update() and delete() fire, and the state
 * listeners see.
 *
 * new() writes the row, then sets prime once the id exists. Listeners must
 * only ever see the finished row: a listener reading ->prime on the first
 * write once recursed through a relation of the same name, and would still
 * silently read null.
 */
class RevisionEventsTest extends TestCase
{
    private const EVENTS = ['saving', 'creating', 'created', 'updating', 'updated', 'saved'];

    private const DELETE_EVENTS = ['deleting', 'trashed', 'deleted'];

    /** @var array<int, array{event: string, id: mixed, prime: mixed}> */
    private array $fired = [];

    private function recordPostEvents(array $events = self::EVENTS): void
    {
        foreach ($events as $event) {
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

    public function test_deleting_the_prime_fires_the_delete_events_on_it(): void
    {
        $post = Post::new(['title' => 'v1']);
        $this->recordPostEvents(self::DELETE_EVENTS);

        $this->assertTrue($post->delete());

        $this->assertSame(['deleting', 'trashed', 'deleted'], $this->firedEvents());
        $this->assertSame([$post->id], array_unique(array_column($this->fired, 'id')));
    }

    /**
     * delete() on a later revision once bulk-deleted the prime row with a
     * query, which fires no model events: listeners never heard that the
     * chain was deleted, so Scout, for one, never removed it from its index.
     */
    public function test_deleting_through_a_later_revision_fires_the_delete_events_on_the_prime_row(): void
    {
        $this->actingAsUserOnBranch(2);
        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);
        $this->recordPostEvents(self::DELETE_EVENTS);

        $deleted = $revision->delete();

        $this->assertSame(['deleting', 'trashed', 'deleted'], $this->firedEvents());
        $this->assertSame([$post->id], array_unique(array_column($this->fired, 'id')), 'the events fired on a row other than the prime');
        $this->assertSoftDeleted('posts', ['id' => $post->id]);
        $this->assertNotSoftDeleted('posts', ['id' => $revision->id]);
        $this->assertTrue($deleted);
    }

    public function test_a_deleting_listener_can_stop_a_delete_through_a_later_revision(): void
    {
        $this->actingAsUserOnBranch(2);
        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);
        Post::deleting(fn () => false);

        $deleted = $revision->delete();

        $this->assertNotSoftDeleted('posts', ['id' => $post->id]);
        $this->assertFalse($deleted);
    }

    public function test_deleting_through_a_revision_of_an_already_deleted_chain_fires_nothing(): void
    {
        $this->actingAsUserOnBranch(2);
        $post = Post::new(['title' => 'v1']);
        $revision = $post->update(['title' => 'v2']);
        $post->delete();
        $this->recordPostEvents(self::DELETE_EVENTS);

        $this->assertNull($revision->delete());

        $this->assertSame([], $this->fired);
        $this->assertNotSoftDeleted('posts', ['id' => $revision->id]);
    }
}
