<?php

namespace Illuminate\Database\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Attributes\Column;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

trait HasDeclaredAttributes
{
    /**
     * The declared attribute names, keyed by model class.
     *
     * @var array<class-string, list<string>>
     */
    protected static $declaredAttributes = [];

    /**
     * Initialize the HasDeclaredAttributes trait.
     *
     * @return void
     */
    public function initializeHasDeclaredAttributes()
    {
        // Unsetting the typed properties makes PHP route every read and write of them
        // through the model's magic methods, so the values keep living in $attributes
        // and casts, mutators, and dirty tracking behave just like they do today...
        foreach (static::declaredAttributes() as $name) {
            unset($this->{$name});
        }
    }

    /**
     * Get the declared attribute names.
     *
     * @return list<string>
     *
     * @throws \LogicException
     */
    public static function declaredAttributes()
    {
        if (isset(static::$declaredAttributes[static::class])) {
            return static::$declaredAttributes[static::class];
        }

        $declared = [];

        foreach ((new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Column::class) === []) {
                continue;
            }

            if ($property->isStatic() || $property->isReadOnly() ||
                ! $property->hasType() || $property->hasDefaultValue()) {
                throw new LogicException(sprintf(
                    'Column [%s::$%s] must be a typed, non-static, non-readonly property without a default value.',
                    static::class, $property->getName()
                ));
            }

            $declared[] = $property->getName();
        }

        return static::$declaredAttributes[static::class] = $declared;
    }
}
