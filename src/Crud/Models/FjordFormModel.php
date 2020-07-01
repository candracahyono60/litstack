<?php

namespace Fjord\Crud\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Fjord\Crud\Fields\Media\MediaField;
use Fjord\Crud\Fields\Relations\ManyRelationField;
use Fjord\Crud\RelationField;
use Fjord\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

class FjordFormModel extends Model implements HasMedia, TranslatableContract
{
    use Traits\HasMedia;
    use Translatable;
    use Concerns\HasConfig;
    use Concerns\HasFields;
    use Concerns\HasMedia;

    /**
     * "value" is translatable but since non translatable fields are stored in
     * the value field it is important to not set value as a translatedAttribute
     * here.
     *
     * @var array
     */
    public $translatedAttributes = [];

    /**
     * Field ids.
     *
     * @var array
     */
    protected $fieldIds = [];

    /**
     * Casts.
     *
     * @var array
     */
    protected $casts = ['value' => 'json'];

    /**
     * Register media conversions for field.
     *
     * @param SpatieMedia $media
     *
     * @return void
     */
    public function registerMediaConversions(SpatieMedia $media = null): void
    {
        $this->registerCrudMediaConversions($media);
    }

    /**
     * Get translation attribute.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getTranslationAttribute()
    {
        return $this->getTranslationsArray();
    }

    /**
     * Get translations array.
     *
     * @return array
     */
    public function getTranslationsArray(): array
    {
        $translations = [];

        foreach (config('translatable.locales') as $locale) {
            $translation = $this->translations->where('locale', $locale)->first();
            if (! $translation) {
                continue;
            }
            $value = $translation->value ?? [];

            foreach ($this->fields as $field) {
                if (! $field->translatable) {
                    continue;
                }

                if (! array_key_exists($field->local_key, $value)) {
                    $value[$field->local_key] = $this->getFormattedFieldValue($field, $locale);
                }

                $translations[$translation->{$this->getLocaleKey()}] = $value;
            }
        }

        return $translations;
    }

    /**
     * Update FormField.
     *
     * @param array $attributes
     * @param array $options
     *
     * @return void
     */
    public function update(array $attributes = [], array $options = [])
    {
        $translations = $this->getTranslationsArray();
        foreach (config('translatable.locales') as $locale) {
            if (! array_key_exists($locale, $attributes)) {
                continue;
            }
            $translation = array_merge($translations[$locale] ?? [], $attributes[$locale]);

            $attributes[$locale] = ['value' => $translation];
        }

        $attributes['value'] = $this->value ?? [];
        foreach ($attributes as $key => $value) {
            if (! in_array($key, $this->fillable) && ! in_array($key, config('translatable.locales'))) {
                $attributes['value'][$key] = $value;
            }
        }

        return parent::update($attributes, $options);
    }

    /**
     * Get a relationship.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getRelationValue($key)
    {
        // If the key already exists in the relationships array, it just means the
        // relationship has already been loaded, so we'll just return it out of
        // here because there is no need to query within the relations twice.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        // If the "attribute" exists as a method on the model, we will just assume
        // it is a relationship and will load and return results from the query
        // and hydrate the relationship's value on the "relationships" array.
        if (method_exists($this, $key) || in_array($key, $this->fieldIds)) {
            return $this->getRelationshipFromMethod($key);
        }
    }

    /**
     * Get translated field value.
     *
     * @param Field  $field
     * @param string $locale
     *
     * @return void
     */
    public function getTranslatedFieldValue($field, string $locale)
    {
        $value = $this->translation[$locale] ?? [];

        return $value[$field->local_key] ?? null;
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        $attributes['value'] = $attributes['value'] ?? [];

        foreach (($this->fields ?? []) as $field) {
            if ($field->translatable) {
                continue;
            }
            $attributes['value'][$field->local_key] = $this->getFormattedFieldValue($field);
        }

        // For not translated fields.
        $attributes = array_merge($attributes['value'], $attributes);

        return $attributes;
    }

    /**
     * Get attribute.
     *
     * @param string $key
     *
     * @return void
     */
    public function getAttribute($key)
    {
        // Using fieldIds instead of fieldExists to avoid infinite loop
        // when calling getAttribute({field_id}).
        if (! in_array($key, $this->fieldIds)) {
            return parent::getAttribute($key);
        }

        return $this->getFormattedFieldValue(
            $this->findField($key)
        );
    }

    /**
     * Set field ids to be able to check if field exists in getAttribute method.
     *
     * @param array $ids
     *
     * @return void
     */
    public function setFieldIds(array $ids)
    {
        $this->fieldIds = $ids;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @param array       $attributes
     * @param string|null $connection
     *
     * @return static
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = parent::newFromBuilder($attributes, $connection);

        // FIX: config_type
        if (method_exists($this, 'fixConfigType')) {
            $this->fixConfigType($model);
        }

        // Set field ids to be able to check if field exists in getAttribute
        // method.
        $model->setFieldIds($model->fields->map(function ($field) {
            return $field->id;
        })->toArray());

        return $model;
    }

    /**
     * Get the model's relationships in array form.
     *
     * @return array
     */
    public function relationsToArray()
    {
        $attributes = parent::relationsToArray();

        foreach (($this->fields ?? []) as $field) {
            if (! $field instanceof RelationField) {
                continue;
            }

            $attributes[$field->id] = $this->getFormattedFieldValue($field);

            if ($field instanceof MediaField || $field instanceof ManyRelationField) {
                if ($field instanceof MediaField && $field->maxFiles == 1) {
                    continue;
                }
                $items = $this->getFormattedFieldValue($field);
                $attributes["first_{$field->id}"] = $items ? $items->first() : null;
            }
        }

        return $attributes;
    }

    /**
     * Modified to return relation instances for relation fields.
     *
     * @param string $method
     * @param array  $params
     *
     * @return mixed
     */
    public function __call($method, $params = [])
    {
        if (! in_array($method, $this->fieldIds)) {
            return parent::__call($method, $params);
        }

        $field = $this->findField($method);

        if (! $field instanceof RelationField) {
            return parent::__call($method, $params);
        }

        return $field->getRelationQuery($this);
    }
}
