<?php

namespace Cesargb\Database\Support;

use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class Morph
{
    /**
     * Get the classes that use the trait CascadeDelete.
     *
     * @return \Illuminate\Database\Eloquent\Model[]
     */
    public function getCascadeDeleteModels()
    {
        $this->load();

        return array_map(
            function ($modelName) {
                return new $modelName;
            },
            $this->getModelsNameWithCascadeDeleteTrait()
        );
    }

    /**
     * Delete polymorphic relationships of the single records from Model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function delete($model)
    {
        foreach ($this->getValidMorphRelationsFromModel($model) as $relationMorph) {
            if ($relationMorph instanceof MorphOneOrMany) {
                $relationMorph->delete();
            } elseif ($relationMorph instanceof MorphToMany) {
                $relationMorph->detach();
            }
        }
    }

    public function cleanResidual()
    {
        $numRowsDeleted = 0;

        foreach ($this->getCascadeDeleteModels() as $model) {
            $numRowsDeleted += $this->cleanResidualByModel($model);
        }

        return $numRowsDeleted;
    }

    /**
     * Clean residual polymorphic relationships from a Model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return int Num rows was deleted
     */
    public function cleanResidualByModel($model)
    {
        $numRowsDeleted = 0;

        $relationsMorphs = self::getValidMorphRelationsFromModel($model);

        foreach ($relationsMorphs as $relation) {
            if ($relation instanceof MorphOneOrMany) {
                $numRowsDeleted += $this->cleanResidualFromDB(
                    $relation->getRelated()->getTable(),
                    $relation->getMorphType(),
                    $relation->getForeignKeyName()
                );
            } elseif ($relation instanceof MorphToMany) {
                $numRowsDeleted += $this->cleanResidualFromDB(
                    $relation->getTable(),
                    $relation->getMorphType(),
                    $relation->getForeignPivotKeyName()
                );
            }
        }

        return $numRowsDeleted;
    }

    /**
     * Clean residual for a Table.
     *
     * @param string $table      Table with morph relation
     * @param string $fieldType  Field defined for Morph Type
     * @param string $fieldId    Field defined for Morph Id
     * @return int Num rows was deleted
     */
    protected function cleanResidualFromDB($table, $fieldType, $fieldId)
    {
        $numRowsDeleted = 0;

        $parents = DB::table($table)->groupBy($fieldType)->pluck($fieldType);

        foreach ($parents as $parent) {
            if (class_exists($parent)) {
                $parentObject = new $parent;

                $numRowsDeleted += DB::table($table)
                        ->where($fieldType, $parent)
                        ->whereNotExists(function ($query) use (
                            $parentObject,
                            $table,
                            $fieldId
                        ) {
                            $query->select(DB::raw(1))
                                    ->from($parentObject->getTable())
                                    ->whereRaw(
                                        $parentObject->getTable().'.'.$parentObject->getKeyName().' = '.$table.'.'.$fieldId
                                    );
                        })->delete();
            } else {
                $numRowsDeleted += DB::table($table)
                        ->where($fieldType, $parent)
                        ->delete();
            }
        }

        return $numRowsDeleted;
    }

    /**
     * Get the classes names that use the trait CascadeDelete.
     *
     * @return array
     */
    protected function getModelsNameWithCascadeDeleteTrait()
    {
        return array_filter(
            get_declared_classes(),
            function ($class) {
                return array_key_exists(
                    CascadeDelete::class,
                    class_uses($class)
                );
            }
        );
    }

    /**
     * Fetch polymorphic relationships from a Model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    protected function getValidMorphRelationsFromModel($model)
    {
        return array_filter(
            array_map(
                function ($methodName) use ($model) {
                    return $this->methodReturnedMorphRelation($model, $methodName);
                },
                $model->getCascadeDeleteMorph()
            ),
            function ($relation) {
                return $relation;
            }
        );
    }

    /**
     * Verify if method of a Model return a polymorphic relationship.
     *
     * @param \Illuminate\Database\Eloquent\Model  $model
     * @param string                               $methodName
     * @return bool
     */
    protected function methodReturnedMorphRelation($model, $methodName)
    {
        if (!method_exists($model, $methodName)) {
            return null;
        }

        $relation = $model->$methodName();

        return $this->isMorphRelation($relation) ? $relation : null;
    }

    /**
     * Verify if a object is a instance of a polymorphic relationship.
     *
     * @param mixed $relation
     * @return bool
     */
    protected function isMorphRelation($relation)
    {
        return $relation instanceof MorphOneOrMany || $relation instanceof MorphToMany;
    }

    /**
     * Undocumented function
     *
     * @param string|array $path
     * @return void
     */
    protected function load()
    {
        foreach ($this->findModels() as $file) {
            require_once $file->getPathname();
        }
    }

    protected function findModels()
    {
        return Finder::create()
            ->files()
            ->in(config('morph.models_paths', app_path()))
            ->name('*.php')
            ->contains('CascadeDelete');
    }
}
