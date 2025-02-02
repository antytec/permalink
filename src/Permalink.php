<?php

namespace Devio\Permalink;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Cviebrock\EloquentSluggable\Sluggable;
use Devio\Permalink\Contracts\ActionFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\Relation;

class Permalink extends Model
{
    use Sluggable, SoftDeletes;

    /**
     * Fillable attributes.
     *
     * @var array
     */
    public $fillable = ['tenant_id', 'parent_id', 'parent_for', 'entity_type', 'entity_id', 'slug', 'action', 'seo'];

    /**
     * Casting attributes.
     *
     * @var array
     */
    protected $casts = [
        'seo' => 'json'
    ];

    /**
     * Array to map action class paths to their alias names in database.
     *
     * @var
     */
    public static $actionMap = [];

    /**
     * Parents cache.
     *
     * @var array
     */
    public static $parents = [];

    /**
     * Booting the model.
     */
    public static function boot()
    {
        parent::boot();
        parent::flushEventListeners();

        // Since we want to allow the user to specify an slug rather to always
        // generate it automatically, we've to remove the observers added by
        // the Sluggable package, so we control the slug creation/update.
        static::observe(PermalinkObserver::class);
    }

    /**
     * Polymorphic relationship to any entity.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Relationship to the parent permalink.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo+
     */
    public function parent()
    {
        return $this->belongsTo(static::class);
    }

    /**
     * Relationship to the permalink children.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        if (! $entity = $this->getRelationValue('entity')) {
            return [];
        }

        $source = (array) $entity->permalinkSlug();

        // We will look for slug source at the permalinkable entity. That method
        // should return an array with a 'source' key in it. This way the user
        // will be able to provide more parameters to the sluggable options.
        return [
            'slug' => array_key_exists('source', $source) ? $source : compact('source')
        ];
    }

    /**
     * Unique slug constraints.
     *
     * @param Builder $query
     * @param Model $model
     * @param $attribute
     * @param $config
     * @param $slug
     * @return Builder+
     */
    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model, $attribute, $config, $slug)
    {
        if ($slug != '') {
            return $query->where('parent_id', $model->parent_id);
        }
    }

    /**
     * Check if the permalink is nested.
     *
     * @return bool
     */
    public function isNested()
    {
        return (bool) ! is_null($this->parent_id);
    }

    /**
     * Get the default verbs.
     *
     * @return array
     */
    public function getMethodAttribute()
    {
        return ['GET', 'HEAD'];
    }

    /**
     * Alias to get the entity type.
     *
     * @return mixed
     */
    public function getTypeAttribute()
    {
        return $this->entity_type;
    }

    /**
     * Convert an alias to a full action path if any.
     *
     * @return null|string
     */
    public function getActionAttribute()
    {
        return app(ActionFactory::class)->resolve($this);
    }

    /**
     * Replace the action by an alias if any.
     *
     * @param $value
     */
    public function setActionAttribute($value)
    {
        $this->attributes['action'] = array_search($value, static::actionMap()) ?: $value;
    }

    /**
     * Get the raw action value.
     *
     * @return mixed
     */
    public function getRawActionAttribute()
    {
        return $this->attributes['action'] ?? null;
    }

    /**
     * Set the parent for from the morph map if exists.
     *
     * @param $value
     */
    public function setParentForAttribute($value)
    {
        if (! Relation::getMorphedModel($value)) {
            $value = array_search($value, Relation::morphMap()) ?: $value;
        }

        $this->attributes['parent_for'] = $value;
    }

    /**
     * Set or get the alias map for aliased actions
     *
     * @param array|null $map
     * @param bool $merge
     * @return array
     */
    public static function actionMap(array $map = null, $merge = true)
    {
        if (! is_null($map)) {
            static::$actionMap = $merge && static::$actionMap
                ? $map + static::$actionMap : $map;
        }

        return static::$actionMap;
    }

    /**
     * Set all seo values without NULLs.
     *
     * @param $value
     */
    public function setSeoAttribute($value)
    {
        $value = Arr::dot(Arr::wrap($value));

        $this->attributes['seo'] = json_encode(Arr::undot(
            array_filter($value, function ($item) {
                return $item ?? false;
            })
        ));
    }

    /**
     * Get the action associated with a custom alias.
     *
     * @param string $alias
     * @return string|null
     */
    public static function getMappedAction($alias)
    {
        return static::$actionMap[$alias] ?? null;
    }
}
