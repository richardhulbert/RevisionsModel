<?php

namespace RichardHulbert\Revisions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Extend from this model when your model has the pattern id > prime (revisions).
 * A record is never updated in place: update() clones the row with the new
 * values, so the full history stacks up and any change can be permanently
 * rolled back. Every revision carries the branch it was made on (branch_id)
 * and the user who made it (user_id); `prime` always points at the original
 * record of the chain.
 *
 * @property int $prime
 * @property int $user_id
 * @property int $branch_id
 *
 * @author Richard Hulbert
 */
abstract class RevisionsModel extends Model
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['prime', 'user_id', 'branch_id']);
        parent::__construct($attributes);
    }

    /**
     * Returns a merged array with ['prime','user_id','branch_id'] added.
     * Kept for backwards compatibility - the constructor now merges these
     * into $fillable automatically, so subclasses no longer need to call it.
     */
    public static function mergeRevisionFillable(array $sub_array): array
    {
        return array_merge($sub_array, ['prime', 'user_id', 'branch_id']);
    }

    /**
     * The id of the branch that holds public / published content.
     */
    public static function publicBranchId(): int
    {
        return (int) config('revisions.public_branch_id', 1);
    }

    /**
     * The branch the authenticated user is currently working on, or null
     * when no user is logged in.
     */
    protected static function authBranchId(): ?int
    {
        $attribute = config('revisions.user_branch_attribute', 'branch');
        $branch = Auth::user()?->{$attribute};

        return $branch === null ? null : (int) $branch;
    }

    /**
     * The configured user model (falls back to the default auth provider).
     */
    protected static function userModel(): string
    {
        return config('revisions.user_model')
            ?? config('auth.providers.users.model');
    }

    /**
     * The configured branch model.
     */
    protected static function branchModel(): string
    {
        return config('revisions.branch_model');
    }

    /**
     * A hasOne that includes soft-deleted rows when the related model
     * supports them, so relations survive a deleted branch or user.
     */
    protected function hasOneWithTrashed(string $related, string $foreignKey, string $localKey): HasOne
    {
        $relation = $this->hasOne($related, $foreignKey, $localKey);

        if (in_array(SoftDeletes::class, class_uses_recursive($related))) {
            $relation->withTrashed();
        }

        return $relation;
    }

    /**
     * Get a list of all revisions of this record (optionally on one branch):
     * the latest revision per branch per day.
     */
    public function revisions(?int $branch = null): Collection
    {
        $table = $this->getTable();

        // Build the subquery using a closure to preserve bindings
        return static::with('branch', 'owner')
            ->where('prime', '=', $this->prime)
            ->orderBy('id', 'desc')
            ->whereIn('id', function ($query) use ($table, $branch) {
                $query->selectRaw('MAX(id)')
                    ->from($table)
                    ->where('prime', $this->prime)
                    ->when($branch !== null, function ($q) use ($branch) {
                        $q->where('branch_id', $branch);
                    })
                    ->groupBy('branch_id')
                    ->groupByRaw('DATE(created_at)');
            })
            ->get();
    }

    /**
     * A hasOne to another RevisionsModel: the latest revision of the related
     * model on the given branch, matched on the related model's prime. Unlike
     * a plain latestOfMany(), the branch constraint is applied inside the
     * one-of-many aggregate so MAX(id) is computed per branch, and the
     * relation stays eager-loadable (works with with()/load()).
     *
     * @param string $related the related RevisionsModel class
     * @param string $localKey the column on this model holding the related model's prime
     * @param int|null $branch defaults to the current user's branch, or the public branch
     */
    public function hasOneRevision(string $related, string $localKey, ?int $branch = null): HasOne
    {
        $branch = $branch ?? static::authBranchId() ?? static::publicBranchId();

        return $this->hasOne($related, 'prime', $localKey)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('branch_id', $branch));
    }

    /**
     * The branch this revision was made on, even if the branch has since
     * been (soft) deleted.
     */
    public function branch(): HasOne
    {
        return $this->hasOneWithTrashed(static::branchModel(), 'id', 'branch_id');
    }

    /**
     * The user who made this revision, even if since (soft) deleted.
     */
    public function owner(): HasOne
    {
        return $this->hasOneWithTrashed(static::userModel(), 'id', 'user_id');
    }

    /**
     * Get the last revision. Will return the public branch version if there
     * is no version on the current user's branch.
     */
    public function scopeLastRevision(): HasOne
    {
        $branch = static::authBranchId();

        if ($branch !== null && static::where('prime', $this->prime)->where('branch_id', $branch)->count() > 0) {
            return $this->lastRevisionWithBranch();
        }

        return $this->lastPublicRevision();
    }

    /**
     * The last revision on a branch.
     *
     * @param int|null $branch falls back to the user's branch, then the public branch
     */
    public function lastRevisionWithBranch(?int $branch = null): HasOne
    {
        $branch = $branch ?? static::authBranchId() ?? static::publicBranchId();

        return $this->hasOne(static::class, 'prime')
            ->where('branch_id', '=', $branch)
            ->orderBy('id', 'desc');
    }

    /**
     * Get the last revision on the public branch.
     */
    public function lastPublicRevision(): HasOne
    {
        return $this->hasOne(static::class, 'prime')
            ->where('branch_id', '=', static::publicBranchId())
            ->orderBy('id', 'desc');
    }

    /**
     * Creates a new record and sets prime equal to its own id, starting a
     * new revision chain.
     *
     * @return static
     */
    public static function new(array $args): static
    {
        $new = static::create($args);
        $new->prime = $new->id;
        $new->save();

        return $new;
    }

    /**
     * Clones the current model, sets branch_id and user_id from the current
     * user (unless given in $attributes), applies the changes, saves and
     * returns the new revision. The existing row is left untouched.
     *
     * @return static
     */
    public function update(array $attributes = [], array $options = []): RevisionsModel
    {
        $new = $this->replicate();
        $user = Auth::user();

        // allow for a branch to be specified
        if (! isset($attributes['branch_id'])) {
            $attributes['branch_id'] = static::authBranchId() ?? static::publicBranchId();
        }
        $attributes['user_id'] = $user->id ?? config('revisions.default_user_id', 1);

        $new->fill($attributes)->save($options);

        return $new;
    }

    /**
     * Deleting any revision deletes the prime record of the chain.
     */
    public function delete()
    {
        // if this is the prime - just delete it
        if ((int) $this->prime === (int) $this->id) {
            return parent::delete();
        }

        return static::whereKey($this->prime)->delete();
    }

    /**
     * Retrieve the latest revision of every record (chain) on a branch.
     *
     * @param int|null $branch defaults to the public branch
     */
    public static function allLatest(?int $branch = null): Builder
    {
        $branch = $branch ?? static::publicBranchId();
        $table = (new static)->getTable();

        return static::with('branch')->whereIn('id', function ($query) use ($table, $branch) {
            $query->select(DB::raw('MAX(id)'))
                ->where('branch_id', '=', $branch)
                ->from($table)
                ->groupBy('prime');
        });
    }
}
