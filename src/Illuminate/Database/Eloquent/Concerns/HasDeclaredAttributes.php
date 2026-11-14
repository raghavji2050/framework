<?php

namespace Illuminate\Database\Eloquent\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Column;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

trait HasDeclaredAttributes
{
    /**
     * The declared attributes and their implicit casts, keyed by model class.
     *
     * @var array<class-string, array<string, string|null>>
     */
    protected static $declaredAttributes = [];

    /**
     * The default values for declared attributes, keyed by model class.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected static $declaredAttributeDefaults = [];

    /**
     * Initialize the HasDeclaredAttributes trait.
     *
     * @return void
     */
    public function initializeHasDeclaredAttributes()
    {
        $declared = static::declaredAttributes();

        // Unsetting the typed properties makes PHP route every read and write of them
        // through the model's magic methods, so the values keep living in $attributes
        // and casts, mutators, and dirty tracking behave just like they do today...
        foreach ($declared as $name => $cast) {
            unset($this->{$name});
        }

        $this->casts = array_merge(array_filter($declared), $this->casts);

        foreach (static::$declaredAttributeDefaults[static::class] ?? [] as $name => $value) {
            if (! array_key_exists($name, $this->attributes)) {
                $this->setAttribute($name, $value);
            }
        }
    }

    /**
     * Get the declared attributes and their implicit casts.
     *
     * @return array<string, string|null>
     *
     * @throws \LogicException
     */
    public static function declaredAttributes()
    {
        if (isset(static::$declaredAttributes[static::class])) {
            return static::$declaredAttributes[static::class];
        }

        $declared = [];
        $defaults = [];

        foreach ((new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Column::class) === []) {
                continue;
            }

            if ($property->isStatic() || $property->isReadOnly() || $property->hasHooks() ||
                ! $property->hasType()) {
                throw new LogicException(sprintf(
                    'Column [%s::$%s] must be a typed, non-static, non-readonly property without hooks.',
                    static::class, $property->getName()
                ));
            }

            $declared[$property->getName()] = static::implicitCastForType($property->getType());

            if ($property->hasDefaultValue()) {
                $defaults[$property->getName()] = $property->getDefaultValue();
            }
        }

        static::$declaredAttributeDefaults[static::class] = $defaults;

        return static::$declaredAttributes[static::class] = $declared;
    }

    /**
     * Get the cast implied by a declared property type.
     *
     * @param  \ReflectionType  $type
     * @return string|null
     */
    protected static function implicitCastForType($type)
    {
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        $name = $type->getName();

        return match (true) {
            $name === 'int' => 'integer',
            $name === 'float' => 'float',
            $name === 'bool' => 'boolean',
            $name === 'array' => 'array',
            $type->isBuiltin() => null,
            enum_exists($name) => $name,
            is_a($name, DateTimeImmutable::class, true) => 'immutable_datetime',
            is_a($name, DateTimeInterface::class, true) => 'datetime',
            default => null,
        };
    }
}
