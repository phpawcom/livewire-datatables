<?php

namespace Mediconesystems\LivewireDatatables\Http\Livewire;

use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Mediconesystems\LivewireDatatables\Column;
use Mediconesystems\LivewireDatatables\ColumnSet;
use Mediconesystems\LivewireDatatables\Exports\DatatableExport;
use Mediconesystems\LivewireDatatables\Traits\WithCallbacks;
use Mediconesystems\LivewireDatatables\Traits\WithPresetDateFilters;
use Mediconesystems\LivewireDatatables\Traits\WithPresetTimeFilters;

class LivewireDatatable extends Component
{
    use WithPagination, WithCallbacks, WithPresetDateFilters, WithPresetTimeFilters;

    const SEPARATOR = '|**lwdt**|';
    
    #[Rule('required')]
    public $model;
    
    public $columns;
    public $search;
    public $sort;
    public $direction;
    public $activeDateFilters = [];
    public $activeDatetimeFilters = [];
    public $activeTimeFilters = [];
    public $activeSelectFilters = [];
    public $activeBooleanFilters = [];
    public $activeTextFilters = [];
    public $activeNumberFilters = [];
    public $defaultFilters = [];
    public $hideHeader;
    public $hidePagination;
    public $perPage;
    public $include;
    public $exclude;
    public $hide;
    public $dates;
    public $times;
    public $searchable;
    public $exportable;
    public $hideable;
    public $params;
    public $selected = [];
    public $beforeTableSlot;
    public $buttonsSlot;
    public $afterTableSlot;
    public $complex;
    public $complexQuery;
    public $title;
    public $name;
    public $columnGroups = [];
    public $userFilter;
    public $persistSearch = true;
    public $persistComplexQuery = true;
    public $persistHiddenColumns = true;
    public $persistSort = true;
    public $persistPerPage = true;
    public $persistFilters = true;
    public $visibleSelected = [];
    public $row = 1;
    public $tablePrefix = '';
    public $actions;
    public $massActionOption;

    /**
     * @var array List your groups and the corresponding label (or translation) here.
     *            The label can be a i18n placeholder like 'app.my_string' and it will be automatically translated via __().
     *
     * Group labels are optional. If they are omitted, the 'name' of the group will be displayed to the user.
     *
     * @example ['group1' => 'app.toggle_group1', 'group2' => 'app.toggle_group2']
     */
    public $groupLabels = [];

    protected $query;
    

    protected $operators = [
        '=' => '=',
        '>' => '>',
        '<' => '<',
        '<>' => '<>',
        '>=' => '>=',
        '<=' => '<=',
        'equals' => '=',
        'does not equal' => '<>',
        'contains' => 'LIKE',
        'does not contain' => 'NOT LIKE',
        'begins with' => 'LIKE',
        'ends with' => 'LIKE',
        'is empty' => '=',
        'is not empty' => '<>',
        'includes' => '=',
        'does not include' => '<>',
    ];

    protected $viewColumns = [
        'index',
        'hidden',
        'label',
        'tooltip',
        'group',
        'summary',
        'content',
        'headerAlign',
        'contentAlign',
        'type',
        'filterable',
        'hideable',
        'sortable',
        'complex',
        'filterView',
        'name',
        'params',
        'wrappable',
        'width',
        'minWidth',
        'maxWidth',
        'preventExport',
    ];

    /**
     * This events allows to control the options of the datatable from foreign livewire components
     * by using dispatch.
     *
     * @example $this->dispatch('applyToTable', ['perPage' => 25]); // in any other livewire component on the same page
     */
    #[On('applyToTable')]
    public function applyToTable($options)
    {
        if (isset($options['sort'])) {
            $this->sort($options['sort'], $options['direction'] ?? null);
        }

        if (isset($options['hiddenColumns']) && is_array($options['hiddenColumns'])) {
            // first display all columns,
            $this->resetHiddenColumns();

            // then hide all columns that should be hidden:
            foreach ($options['hiddenColumns'] as $columnToHide) {
                foreach ($this->columns as $key => $column) {
                    if ($column['name'] === $columnToHide) {
                        $this->columns[$key]['hidden'] = true;
                    }
                }
            }
        }

        foreach ([
            'perPage',
            'search',
            'activeSelectFilters',
            'activeDateFilters',
            'activeDatetimeFilters',
            'activeTimeFilters',
            'activeBooleanFilters',
            'activeTextFilters',
            'activeNumberFilters',
            'hide',
            'selected',
            'pinnedRecords',
        ] as $property) {
            if (isset($options[$property])) {
                $this->$property = $options[$property];
            }
        }

        $this->setSessionStoredFilters();
    }

    /**
     * Call to clear all searches, filters, selections, return to page 1 and set perPage to default.
     */
    #[On('resetTable')]
    public function resetTable()
    {
        $this->perPage = config('livewire-datatables.default_per_page', 10);
        $this->sort = $this->defaultSort();
        $this->search = null;
        $this->setPage(1);
        $this->activeSelectFilters = [];
        $this->activeDateFilters = [];
        $this->activeDatetimeFilters = [];
        $this->activeTimeFilters = [];
        $this->activeTextFilters = [];
        $this->activeBooleanFilters = [];
        $this->activeNumberFilters = [];
        $this->hide = null;
        $this->resetHiddenColumns();
        $this->selected = [];
    }

    /**
     * Display all columns, also those that are currently hidden.
     * Should get called when resetting the table.
     */
    public function resetHiddenColumns()
    {
        foreach ($this->columns as $key => $column) {
            $this->columns[$key]['hidden'] = false;
        }
    }

    public function updatedSearch()
    {
        $this->visibleSelected = ($this->search) ? array_intersect($this->getQuery()->get()->pluck('checkbox_attribute')->toArray(), $this->selected) : $this->selected;
        $this->setPage(1);
    }

    public function mount(
        $model = false,
        $include = [],
        $exclude = [],
        $hide = [],
        $dates = [],
        $times = [],
        $searchable = [],
        $sort = null,
        $hideHeader = null,
        $hidePagination = null,
        $perPage = null,
        $exportable = false,
        $hideable = false,
        $beforeTableSlot = false,
        $buttonsSlot = false,
        $afterTableSlot = false,
        $params = []
    ) {
        foreach ([
            'model',
            'include',
            'exclude',
            'hide',
            'dates',
            'times',
            'searchable',
            'sort',
            'hideHeader',
            'hidePagination',
            'exportable',
            'hideable',
            'beforeTableSlot',
            'buttonsSlot',
            'afterTableSlot',
        ] as $property) {
            $this->$property = $this->$property ?? $$property;
        }

        $this->params = $params;

        $this->columns = $this->getViewColumns();
        $this->actions = $this->getMassActions();
        $this->initialiseSearch();
        $this->initialiseSort();
        $this->initialiseHiddenColumns();
        $this->initialiseDefaultFilters();
        $this->initialiseFilters();
        $this->initialisePerPage();
        $this->initialiseColumnGroups();
        $this->model = $this->model ?: get_class($this->builder()->getModel());

        if (isset($this->pinnedRecords)) {
            $this->initialisePinnedRecords();
        }
    }

    #[On('dehydrate')]
    public function handleDehydrate()
    {
        if ($this->persistSearch) {
            session()->put($this->sessionStorageKey() . '_search', $this->search);
        }
    }

    public function columns()
    {
        return $this->modelInstance;
    }

    public function getViewColumns()
    {
        return collect($this->freshColumns)->map(function ($column) {
            return collect($column)
                ->only($this->viewColumns)
                ->toArray();
        })->toArray();
    }

    public function getComplexColumnsProperty()
    {
        return collect($this->columns)->filter(function ($column) {
            return $column['filterable'];
        });
    }

    public function getPersistKeyProperty()
    {
        return $this->persistComplexQuery
            ? Str::kebab(Str::afterLast(get_class($this), '\\'))
            : null;
    }

    public function getModelInstanceProperty()
    {
        return $this->model::firstOrFail();
    }

    public function builder()
    {
        return $this->model::query();
    }

    public function delete($id)
    {
        $this->model::destroy($id);
    }

    public function getProcessedColumnsProperty()
    {
        return ColumnSet::build($this->columns())
            ->include($this->include)
            ->exclude($this->exclude)
            ->hide($this->hide)
            ->formatDates($this->dates)
            ->formatTimes($this->times)
            ->search($this->searchable)
            ->sort($this->sort);
    }

    public function resolveAdditionalSelects($column)
    {
        $selects = collect($column->additionalSelects)->map(function ($select) use ($column) {
            return Str::contains($select, '.')
                ? $this->resolveColumnName($column, $select)
                : $this->query->getModel()->getTable() . '.' . $select;
        });

        if (DB::connection() instanceof \Illuminate\Database\SQLiteConnection) {
            // SQLite dialect.
            return $selects->count() > 1
                ? new Expression('(' .
                collect($selects)->map(function ($select) {
                    return 'COALESCE(' . $this->tablePrefix . $select . ', \'\')';
                })->join(" || '" . static::SEPARATOR . "' || ") . ')')
                : $selects->first();
        } else {
            // Default to MySql dialect.
            return $selects->count() > 1
                ? new Expression("CONCAT_WS('" . static::SEPARATOR . "' ," .
                collect($selects)->map(function ($select) {
                    return 'COALESCE(' . $this->tablePrefix . $select . ', \'\')';
                })->join(', ') . ')')
                : $selects->first();
        }
    }

    public function resolveEditableColumnName($column)
    {
        return [
            $column->select,
            $this->query->getModel()->getTable() . '.' . $this->query->getModel()->getKeyName() . ' AS ' . $column->name . '_edit_id',
        ];
    }

    public function getSelectStatements($withAlias = false, $export = false)
    {
        return $this->processedColumns->columns
            ->reject(function ($column) use ($export) {
                return $column->scope || $column->type === 'label' || ($export && $column->preventExport);
            })->map(function ($column) {
                if ($column->select) {
                    return $column;
                }

                if (Str::startsWith($column->name, 'callback_')) {
                    $column->select = $this->resolveAdditionalSelects($column);

                    return $column;
                }

                $column->select = $this->resolveColumnName($column);

                if ($column->isEditable()) {
                    $column->select = $this->resolveEditableColumnName($column);
                }

                return $column;
            })->when($withAlias, function ($columns) {
                return $columns->map(function ($column) {
                    if (! $column->select) {
                        return null;
                    }
                    if ($column->select instanceof Expression) {
                        $sep_string = config('database.default') === 'pgsql' ? '"' : '`';

                        if (version_compare('10.0.0', app()->version()) == -1) {
                            return new Expression($column->select->getValue(DB::getQueryGrammar()) . ' AS ' . $sep_string . $column->name . $sep_string);
                        } else {
                            return new Expression($column->select->getValue() . ' AS ' . $sep_string . $column->name . $sep_string);
                        }
                    }

                    if (is_array($column->select)) {
                        $selects = $column->select;
                        $first = array_shift($selects) . ' AS ' . $column->name;
                        $others = array_map(function ($select) {
                            return $select . ' AS ' . $select;
                        }, $selects);

                        return array_merge([$first], $others);
                    }

                    return $column->select . ' AS ' . $column->name;
                });
            }, function ($columns) {
                return $columns->map->select;
            });
    }

    protected function resolveColumnName($column, $additional = null)
    {
        if ($column->isBaseColumn()) {
            return $this->query->getModel()->getTable() . '.' . ($column->base ?? Str::before($column->name, ':'));
        }

        $relations = explode('.', Str::before($additional ?: $column->name, ':'));
        $aggregate = Str::after($additional ?: $column->name, ':');

        if (! method_exists($this->query->getModel(), $relations[0])) {
            return $additional ?: $column->name;
        }

        $columnName = array_pop($relations);
        $aggregateName = implode('.', $relations);

        $relatedQuery = $this->query;

        while (count($relations) > 0) {
            $relation = array_shift($relations);

            if ($relatedQuery->getRelation($relation) instanceof HasMany || $relatedQuery->getRelation($relation) instanceof HasManyThrough || $relatedQuery->getRelation($relation) instanceof BelongsToMany) {
                $this->query->customWithAggregate($aggregateName, $column->aggregate ?? 'count', $columnName, $column->name);
                return null;
            }

            $useThrough = collect($this->query->getQuery()->joins)
                ->pluck('table')
                ->contains($relatedQuery->getRelation($relation)->getRelated()->getTable());

            $relatedQuery = $this->query->joinRelation($relation, null, 'left', $useThrough, $relatedQuery);
        }

        return $relatedQuery->getQuery()->from . '.' . $columnName;
    }

    public function getFreshColumnsProperty()
    {
        $columns = $this->processedColumns->columnsArray();

        $duplicates = collect($columns)->reject(function ($column) {
            return in_array($column['type'], Column::UNSORTABLE_TYPES);
        })->pluck('name')->duplicates();

        if ($duplicates->count()) {
            throw new Exception('Duplicate Column Name(s): ' . implode(', ', $duplicates->toArray()));
        }

        return $columns;
    }

    public function sessionStorageKey()
    {
        return Str::snake(Str::afterLast(get_called_class(), '\\')) . $this->name;
    }

    public function getSessionStoredSort()
    {
        if (! $this->persistSort) {
            return;
        }

        $this->sort = session()->get($this->sessionStorageKey() . '_sort', $this->sort);
        $this->direction = session()->get($this->sessionStorageKey() . '_direction', $this->direction);
    }

    public function getSessionStoredPerPage()
    {
        if (! $this->persistPerPage) {
            return;
        }

        $this->perPage = session()->get($this->sessionStorageKey() . $this->name . '_perpage', $this->perPage);
    }

    public function setSessionStoredSort()
    {
        if (! $this->persistSort) {
            return;
        }

        session()->put([
            $this->sessionStorageKey() . '_sort' => $this->sort,
            $this->sessionStorageKey() . '_direction' => $this->direction,
        ]);
    }

    public function setSessionStoredFilters()
    {
        if (! $this->persistFilters) {
            return;
        }

        session()->put([
            $this->sessionStorageKey() . '_filter' => [
                'text' => $this->activeTextFilters,
                'boolean' => $this->activeBooleanFilters,
                'select' => $this->activeSelectFilters,
                'date' => $this->activeDateFilters,
                'datetime' => $this->activeDatetimeFilters,
                'time' => $this->activeTimeFilters,
                'number' => $this->activeNumberFilters,
                'search' => $this->search,
            ],
        ]);
    }

    public function setSessionStoredHidden()
    {
        if (! $this->persistHiddenColumns) {
            return;
        }

        $hidden = collect($this->columns)->filter->hidden->keys()->toArray();

        session()->put([$this->sessionStorageKey() . $this->name . '_hidden_columns' => $hidden]);
    }

    public function initialiseSearch()
    {
        if (! $this->persistSearch) {
            return;
        }

        $this->search = session()->get($this->sessionStorageKey() . '_search', $this->search);
    }

    public function initialiseSort()
    {
        $this->sort = $this->defaultSort()
        ? $this->defaultSort()['key']
        : collect($this->freshColumns)->reject(function ($column) {
            return in_array($column['type'], Column::UNSORTABLE_TYPES) || $column['hidden'];
        })->keys()->first();

        $this->direction = $this->defaultSort() && $this->defaultSort()['direction'] === 'asc';
        $this->getSessionStoredSort();
    }

    public function initialiseHiddenColumns()
    {
        if (! $this->persistHiddenColumns) {
            return;
        }

        if (session()->has($this->sessionStorageKey() . '_hidden_columns')) {
            $this->columns = collect($this->columns)->map(function ($column, $index) {
                $column['hidden'] = in_array($index, session()->get($this->sessionStorageKey() . '_hidden_columns'));

                return $column;
            })->toArray();
        }
    }

    public function initialisePerPage()
    {
        $this->getSessionStoredPerPage();

        if (! $this->perPage) {
            $this->perPage = $this->perPage ?? config('livewire-datatables.default_per_page', 10);
        }
    }

    public function initialiseColumnGroups()
    {
        array_map(function ($column) {
            if (isset($column['group'])) {
                $this->columnGroups[$column['group']][] = $column['name'] ?? $column['label'];
            }
        }, $this->columns);
    }

    public function initialiseDefaultFilters()
    {
        if (! $this->defaultFilters || ! is_array($this->defaultFilters) || count($this->defaultFilters) === 0) {
            return;
        }

        $columns = collect($this->columns);

        foreach ($this->defaultFilters as $columnName => $value) {
            $columnIndex = $columns->search(function ($column) use ($columnName) {
                return $column['name'] === $columnName;
            });

            if ($columnIndex === false) {
                continue;
            }

            $column = $columns[$columnIndex];

            if ($column['type'] === 'string') {
                $this->activeTextFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'boolean') {
                $this->activeBooleanFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'select') {
                $this->activeSelectFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'date') {
                $this->activeDateFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'datetime') {
                $this->activeDatetimeFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'time') {
                $this->activeTimeFilters[$columnIndex] = $value;
            }

            if ($column['type'] === 'number') {
                $this->activeNumberFilters[$columnIndex] = $value;
            }
        }
    }

    public function initialiseFilters()
    {
        if (! $this->persistFilters) {
            return;
        }

        $filters = session()->get($this->sessionStorageKey() . '_filter');

        if (! empty($filters['text'])) {
            $this->activeTextFilters = $filters['text'];
        }

        if (! empty($filters['boolean'])) {
            $this->activeBooleanFilters = $filters['boolean'];
        }

        if (! empty($filters['select'])) {
            $this->activeSelectFilters = $filters['select'];
        }

        if (! empty($filters['date'])) {
            $this->activeDateFilters = $filters['date'];
        }

        if (! empty($filters['datetime'])) {
            $this->activeDatetimeFilters = $filters['datetime'];
        }

        if (! empty($filters['time'])) {
            $this->activeTimeFilters = $filters['time'];
        }

        if (! empty($filters['number'])) {
            $this->activeNumberFilters = $filters['number'];
        }

        if (isset($filters['search'])) {
            $this->search = $filters['search'];
        }
    }

    public function defaultSort()
    {
        $columnIndex = collect($this->freshColumns)->search(function ($column) {
            return is_string($column['defaultSort']);
        });

        return is_numeric($columnIndex) ? [
            'key' => $columnIndex,
            'direction' => $this->freshColumns[$columnIndex]['defaultSort'],
        ] : null;
    }

    public function getSortString($dbtable)
    {
        $column = $this->freshColumns[$this->sort];
        switch (true) {
            case $column['sort']:
                return $column['sort'];
                break;

            case $column['base']:
                return $column['base'];
                break;

            case is_array($column['select']):
                return Str::before($column['select'][0], ' AS ');
                break;

            case is_object($column['select']):
                return Str::before($column['select']->getValue(DB::connection()->getQueryGrammar()), ' AS ');
                break;

            case $column['select']:
                return Str::before($column['select'], ' AS ');
                break;

            default:

                switch ($dbtable) {
                    case 'mysql':
                        return new Expression('`' . $column['name'] . '`');
                        break;
                    case 'pgsql':
                        return new Expression('"' . $column['name'] . '"');
                        break;
                    case 'sqlsrv':
                        return new Expression("'" . $column['name'] . "'");
                        break;
                    default:
                        return new Expression("'" . $column['name'] . "'");
                }
        }
    }

    /**
     * @return bool has the user defined at least one column to display a summary row?
     */
    public function hasSummaryRow()
    {
        foreach ($this->columns as $column) {
            if ($column['summary']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempt so summarize each data cell of the given column.
     * In case we have a string or any other value that is not summarizable,
     * we return a empty string.
     */
    public function summarize($column)
    {
        try {
            return $this->results->sum($column);
        } catch (\TypeError $e) {
            return '';
        }
    }

    public function updatingPerPage()
    {
        $this->refreshLivewireDatatable();
    }
    
    #[On('refreshLivewireDatatable')]
    public function refreshLivewireDatatable()
    {
        $this->setPage(1);
    }

    /**
     * Order the table by a given column index starting from 0.
     *
     * @param  int  $index  which column to sort by
     * @param  string|null  $direction  needs to be 'asc' or 'desc'. set to null to toggle the current direction.
     * @return void
     */
    #[On('sort')]
    public function sort($index, $direction = null)
    {
        if (! in_array($direction, [null, 'asc', 'desc'])) {
            throw new \Exception("Invalid direction $direction given in sort() method. Allowed values: asc, desc.");
        }

        if ($this->sort === (int) $index) {
            if ($direction === null) { // toggle direction
                $this->direction = ! $this->direction;
            } else {
                $this->direction = $direction === 'asc' ? true : false;
            }
        } else {
            $this->sort = (int) $index;
        }
        if ($direction !== null) {
            $this->direction = $direction === 'asc' ? true : false;
        }
        $this->setPage(1);

        session()->put([
            $this->sessionStorageKey() . '_sort' => $this->sort,
            $this->sessionStorageKey() . '_direction' => $this->direction,
        ]);
    }

    public function toggle($index)
    {
        if ($this->sort == $index) {
            $this->initialiseSort();
        }

        if (! $this->columns[$index]['hidden']) {
            unset($this->activeSelectFilters[$index]);
        }

        $this->columns[$index]['hidden'] = ! $this->columns[$index]['hidden'];

        $this->setSessionStoredHidden();
    }
    #[On('toggleGroup')]
    public function toggleGroup($group)
    {
        if ($this->isGroupVisible($group)) {
            $this->hideGroup($group);
        } else {
            $this->showGroup($group);
        }
    }

    public function showGroup($group)
    {
        foreach ($this->columns as $key => $column) {
            if ($column['group'] === $group) {
                $this->columns[$key]['hidden'] = false;
            }
        }

        $this->setSessionStoredHidden();
    }

    public function hideGroup($group)
    {
        foreach ($this->columns as $key => $column) {
            if ($column['group'] === $group) {
                $this->columns[$key]['hidden'] = true;
            }
        }

        $this->setSessionStoredHidden();
    }

    /**
     * @return bool returns true if all columns of the given group are _completely_ visible.
     */
    public function isGroupVisible($group)
    {
        foreach ($this->columns as $column) {
            if ($column['group'] === $group && $column['hidden']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool returns true if all columns of the given group are _completely_ hidden.
     */
    public function isGroupHidden($group)
    {
        foreach ($this->columns as $column) {
            if ($column['group'] === $group && ! $column['hidden']) {
                return false;
            }
        }

        return true;
    }

    public function doBooleanFilter($index, $value)
    {
        $this->activeBooleanFilters[$index] = $value;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doSelectFilter($index, $value)
    {
        $this->activeSelectFilters[$index][] = $value;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    #[On('doTextFilter')]
    public function doTextFilter($index, $value)
    {
        foreach (explode(' ', $value) as $val) {
            $this->activeTextFilters[$index][] = $val;
        }
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doDateFilterStart($index, $start)
    {
        $this->activeDateFilters[$index]['start'] = $start;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doDateFilterEnd($index, $end)
    {
        $this->activeDateFilters[$index]['end'] = $end;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doDatetimeFilterStart($index, $start)
    {
        $this->activeDatetimeFilters[$index]['start'] = $start;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doDatetimeFilterEnd($index, $end)
    {
        $this->activeDatetimeFilters[$index]['end'] = $end;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doTimeFilterStart($index, $start)
    {
        $this->activeTimeFilters[$index]['start'] = $start;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doTimeFilterEnd($index, $end)
    {
        $this->activeTimeFilters[$index]['end'] = $end;
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doNumberFilterStart($index, $start)
    {
        $this->activeNumberFilters[$index]['start'] = ($start != '') ? (int) $start : null;
        $this->clearEmptyNumberFilter($index);
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function doNumberFilterEnd($index, $end)
    {
        $this->activeNumberFilters[$index]['end'] = ($end != '') ? (int) $end : null;
        $this->clearEmptyNumberFilter($index);
        $this->setVisibleSelected();
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function clearEmptyNumberFilter($index)
    {
        if ((! isset($this->activeNumberFilters[$index]['start']) || $this->activeNumberFilters[$index]['start'] == '') && (! isset($this->activeNumberFilters[$index]['end']) || $this->activeNumberFilters[$index]['end'] == '')) {
            $this->removeNumberFilter($index);
        }
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function removeSelectFilter($column, $key = null)
    {
        unset($this->activeSelectFilters[$column][$key]);
        $this->visibleSelected = $this->selected;
        if (count($this->activeSelectFilters[$column]) < 1) {
            unset($this->activeSelectFilters[$column]);
        }
        $this->setPage(1);
        $this->setSessionStoredFilters();
    }

    public function clearAllFilters()
    {
        $this->activeDateFilters = [];
        $this->activeDatetimeFilters = [];
        $this->activeTimeFilters = [];
        $this->activeSelectFilters = [];
        $this->activeBooleanFilters = [];
        $this->activeTextFilters = [];
        $this->activeNumberFilters = [];
        $this->complexQuery = null;
        $this->userFilter = null;
        $this->visibleSelected = $this->selected;
        $this->setPage(1);
        $this->setSessionStoredFilters();

        $this->dispatch('resetQuery')->to('complex-query');
    }

    public function removeBooleanFilter($column)
    {
        unset($this->activeBooleanFilters[$column]);
        $this->visibleSelected = $this->selected;
        $this->setSessionStoredFilters();
    }

    public function removeTextFilter($column, $key = null)
    {
        if (isset($key)) {
            unset($this->activeTextFilters[$column][$key]);
            if (count($this->activeTextFilters[$column]) < 1) {
                unset($this->activeTextFilters[$column]);
            }
        } else {
            unset($this->activeTextFilters[$column]);
        }
        $this->visibleSelected = $this->selected;
        $this->setSessionStoredFilters();
    }

    public function removeNumberFilter($column)
    {
        unset($this->activeNumberFilters[$column]);
        $this->visibleSelected = $this->selected;
        $this->setSessionStoredFilters();
    }

    public function getColumnFilterStatement($index)
    {
        if ($this->freshColumns[$index]['type'] === 'editable') {
            return [$this->getSelectStatements()[$index][0]];
        }

        if ($this->freshColumns[$index]['filterOn']) {
            return Arr::wrap($this->freshColumns[$index]['filterOn']);
        }

        if ($this->freshColumns[$index]['scope']) {
            return 'scope';
        }

        if ($this->freshColumns[$index]['raw']) {
            return [(string) $this->freshColumns[$index]['sort']];
        }

        return Arr::wrap($this->getSelectStatements()[$index]);
    }

    public function addScopeSelectFilter($query, $index, $value)
    {
        if (! isset($this->freshColumns[$index]['scopeFilter'])) {
            return;
        }

        return $query->{$this->freshColumns[$index]['scopeFilter']}($value);
    }

    public function addScopeNumberFilter($query, $index, $value)
    {
        if (! isset($this->freshColumns[$index]['scopeFilter'])) {
            return;
        }

        return $query->{$this->freshColumns[$index]['scopeFilter']}($value);
    }

    public function addAggregateFilter($query, $index, $filter, $operand = null)
    {
        $column = $this->freshColumns[$index];
        $relation = Str::before($column['name'], '.');
        $aggregate = $this->columnAggregateType($column);
        $field = Str::before(explode('.', $column['name'])[1], ':');

        $filter = Arr::wrap($filter);

        $query->when($column['type'] === 'boolean', function ($query) use ($filter, $relation, $field, $aggregate) {
            $query->where(function ($query) use ($filter, $relation, $field, $aggregate) {
                if (Arr::wrap($filter)[0]) {
                    $query->hasAggregate($relation, $field, $aggregate);
                } else {
                    $query->hasAggregate($relation, $field, $aggregate, '<');
                }
            });
        })->when($aggregate === 'group_concat' && count($filter), function ($query) use ($filter, $relation, $field, $aggregate) {
            $query->where(function ($query) use ($filter, $relation, $field, $aggregate) {
                foreach ($filter as $value) {
                    $query->hasAggregate($relation, $field, $aggregate, 'like', '%' . $value . '%');
                }
            });
        })->when(isset($filter['start']), function ($query) use ($filter, $relation, $field, $aggregate) {
            $query->hasAggregate($relation, $field, $aggregate, '>=', $filter['start']);
        })->when(isset($filter['end']), function ($query) use ($filter, $relation, $field, $aggregate) {
            $query->hasAggregate($relation, $field, $aggregate, '<=', $filter['end']);
        })->when(isset($operand), function ($query) use ($filter, $relation, $field, $aggregate, $operand) {
            $query->hasAggregate($relation, $field, $aggregate, $operand, $filter);
        });
    }

    public function searchableColumns()
    {
        return collect($this->freshColumns)->filter(function ($column, $key) {
            return $column['searchable'];
        });
    }

    public function scopeColumns()
    {
        return collect($this->freshColumns)->filter(function ($column, $key) {
            return isset($column['scope']);
        });
    }

    public function getHeaderProperty()
    {
        return method_exists(static::class, 'header');
    }

    public function getShowHideProperty()
    {
        return $this->showHide() ?? $this->showHide;
    }

    public function getPaginationControlsProperty()
    {
        return $this->paginationControls() ?? $this->paginationControls;
    }

    #[Computed]
    public function getResultsProperty()
    {
        $this->row = 1;

        return $this->mapCallbacks(
            $this->getQuery()->paginate($this->perPage)
        );
    }

    #[Computed]
    public function getSelectFiltersProperty()
    {
        return collect($this->freshColumns)->filter->selectFilter;
    }

    #[Computed]
    public function getBooleanFiltersProperty()
    {
        return collect($this->freshColumns)->filter->booleanFilter;
    }

    #[Computed]
    public function getTextFiltersProperty()
    {
        return collect($this->freshColumns)->filter->textFilter;
    }

    #[Computed]
    public function getNumberFiltersProperty()
    {
        return collect($this->freshColumns)->filter->numberFilter;
    }

    #[Computed]
    public function getActiveFiltersProperty()
    {
        return count($this->activeDateFilters)
            || count($this->activeDatetimeFilters)
            || count($this->activeTimeFilters)
            || count($this->activeSelectFilters)
            || count($this->activeBooleanFilters)
            || count($this->activeTextFilters)
            || count($this->activeNumberFilters)
            || is_array($this->complexQuery)
            || $this->userFilter;
    }

    public function columnIsRelation($column)
    {
        return Str::contains($column['name'], '.') && method_exists($this->builder()->getModel(), Str::before($column['name'], '.'));
    }

    public function columnIsAggregateRelation($column)
    {
        if (! $this->columnIsRelation($column)) {
            return;
        }
        $relation = $this->builder()->getRelation(Str::before($column['name'], '.'));

        return $relation instanceof HasManyThrough || $relation instanceof HasMany || $relation instanceof belongsToMany;
    }

    public function columnAggregateType($column)
    {
        return Str::contains($column['name'], ':')
            ? Str::after(explode('.', $column['name'])[1], ':')
            : (
                $column['type'] === 'string'
                    ? 'group_concat'
                    : 'count'
            );
    }

    public function buildDatabaseQuery($export = false)
    {
        $this->query = $this->builder();

        $this->tablePrefix = $this->query->getConnection()->getTablePrefix() ?? '';

        $this->query->addSelect(
            $this->getSelectStatements(true, $export)
            ->filter()
            ->flatten()
            ->toArray()
        );

        $this->addGlobalSearch()
            ->addScopeColumns()
            ->addSelectFilters()
            ->addBooleanFilters()
            ->addTextFilters()
            ->addNumberFilters()
            ->addDateRangeFilter()
            ->addDatetimeRangeFilter()
            ->addTimeRangeFilter()
            ->addComplexQuery()
            ->addSort();

        if (isset($this->pinnedRecors)) {
            $this->applyPinnedRecords();
        }
    }

    #[On('complexQuery')]
    public function complexQuery($rules)
    {
        $this->complexQuery = $rules;
    }

    public function addComplexQuery()
    {
        if (! $this->complexQuery) {
            return $this;
        }

        $this->query->where(function ($query) {
            $this->processNested($this->complexQuery, $query);
        });

        $this->setPage(1);

        return $this;
    }

    private function complexOperator($operand)
    {
        return $operand ? $this->operators[$operand] : '=';
    }

    private function complexValue($rule)
    {
        if (isset($rule['content']['operand'])) {
            if ($rule['content']['operand'] === 'contains') {
                return '%' . $rule['content']['value'] . '%';
            } elseif ($rule['content']['operand'] === 'does not contain') {
                return '%' . $rule['content']['value'] . '%';
            } elseif ($rule['content']['operand'] === 'begins with') {
                return $rule['content']['value'] . '%';
            } elseif ($rule['content']['operand'] === 'ends with') {
                return '%' . $rule['content']['value'];
            } elseif ($rule['content']['operand'] === 'is empty' || $rule['content']['operand'] === 'is not empty') {
                return '';
            }
        }

        return $rule['content']['value'];
    }

    public function processNested($rules = null, $query = null, $logic = 'and')
    {
        collect($rules)->each(function ($rule) use ($query, $logic) {
            if ($rule['type'] === 'rule' && isset($rule['content']['column'])) {
                $query->where(function ($query) use ($rule) {
                    if (! $this->addScopeSelectFilter($query, $rule['content']['column'], $rule['content']['value'])) {
                        if ($this->columnIsAggregateRelation($this->freshColumns[$rule['content']['column']])) {
                            $query = $this->addAggregateFilter($query, $rule['content']['column'], $this->complexValue($rule), $this->complexOperator($rule['content']['operand']));
                        } else {
                            foreach ($this->getColumnFilterStatement($rule['content']['column']) as $column) {
                                if ($rule['content']['operand'] === 'is empty') {
                                    $query->whereNull($column);
                                } elseif ($rule['content']['operand'] === 'is not empty') {
                                    $query->whereNotNull($column);
                                } elseif ($this->columns[$rule['content']['column']]['type'] === 'boolean') {
                                    if ($rule['content']['value'] === 'true') {
                                        $query->whereNotNull(Str::contains($column, '(') ? DB::raw($column) : $column);
                                    } else {
                                        $query->whereNull(Str::contains($column, '(') ? DB::raw($column) : $column);
                                    }
                                } else {
                                    $col = (isset($this->freshColumns[$rule['content']['column']]['round']) && $this->freshColumns[$rule['content']['column']]['round'] !== null)
                                        ? DB::raw('ROUND(' . $column . ', ' . $this->freshColumns[$rule['content']['column']]['round'] . ')')
                                        : (Str::contains($column, '(') ? DB::raw($column) : $column);

                                    $query->orWhere(
                                        $col,
                                        $this->complexOperator($rule['content']['operand']),
                                        $this->complexValue($rule)
                                    );
                                }
                            }
                        }
                    }
                }, null, null, $logic);
            } else {
                $query->where(function ($q) use ($rule) {
                    $this->processNested($rule['content'], $q, $rule['logic']);
                }, null, null, $logic);
            }
        });

        return $query;
    }

    public function addGlobalSearch()
    {
        if (! $this->search) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach (explode(' ', $this->search) as $search) {
                $query->where(function ($query) use ($search) {
                    $this->searchableColumns()->each(function ($column, $i) use ($query, $search) {
                        $query->orWhere(function ($query) use ($i, $search) {
                            foreach ($this->getColumnFilterStatement($i) as $column) {
                                $query->when(is_array($column), function ($query) use ($search, $column) {
                                    foreach ($column as $col) {
                                        $query->orWhereRaw('LOWER(' . (Str::contains(mb_strtolower($column->getValue(DB::connection()->getQueryGrammar())), 'concat') ? '' : $this->tablePrefix) . $col . ') like ?', '%' . mb_strtolower($search) . '%');
                                    }
                                }, function ($query) use ($search, $column) {
                                    $stringColumn = is_string($column)
                                        ? $column
                                        : $column->getValue(DB::connection()->getQueryGrammar());
                                    $query->orWhereRaw('LOWER(' . (Str::contains(mb_strtolower($stringColumn), 'concat') ? '' : $this->tablePrefix) . $stringColumn . ') like ?', '%' . mb_strtolower($search) . '%');
                                });
                            }
                        });
                    });
                });
            }
        });

        return $this;
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function addScopeColumns()
    {
        $this->scopeColumns()->each(function ($column) {
            $this->query->{$column['scope']}($column['label']);
        });

        return $this;
    }

    public function addSelectFilters()
    {
        if (count($this->activeSelectFilters) < 1) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->activeSelectFilters as $index => $activeSelectFilter) {
                $query->where(function ($query) use ($index, $activeSelectFilter) {
                    foreach ($activeSelectFilter as $value) {
                        if ($this->columnIsAggregateRelation($this->freshColumns[$index])) {
                            $this->addAggregateFilter($query, $index, $activeSelectFilter);
                        } else {
                            if (! $this->addScopeSelectFilter($query, $index, $value)) {
                                if ($this->freshColumns[$index]['type'] === 'json') {
                                    $query->where(function ($query) use ($value, $index) {
                                        foreach ($this->getColumnFilterStatement($index) as $column) {
                                            $query->whereRaw('LOWER(' . $this->tablePrefix . $column . ') like ?', [mb_strtolower("%$value%")]);
                                        }
                                    });
                                } else {
                                    $query->orWhere(function ($query) use ($value, $index) {
                                        foreach ($this->getColumnFilterStatement($index) as $column) {
                                            if (Str::contains(mb_strtolower($column), 'concat')) {
                                                $query->orWhereRaw('LOWER(' . $this->tablePrefix . $column . ') like ?', [mb_strtolower("%$value%")]);
                                            } else {
                                                $query->orWhereRaw($column . ' = ?', $value);
                                            }
                                        }
                                    });
                                }
                            }
                        }
                    }
                });
            }
        });

        return $this;
    }

    public function addBooleanFilters()
    {
        if (count($this->activeBooleanFilters) < 1) {
            return $this;
        }
        $this->query->where(function ($query) {
            foreach ($this->activeBooleanFilters as $index => $value) {
                if ($this->getColumnFilterStatement($index) === 'scope') {
                    $this->addScopeSelectFilter($query, $index, $value);
                } elseif ($this->columnIsAggregateRelation($this->freshColumns[$index])) {
                    $this->addAggregateFilter($query, $index, $value);
                } elseif ($this->freshColumns[$index]['type'] === 'string') {
                    if ($value == 1) {
                        $query->whereNotNull($this->getColumnFilterStatement($index)[0])
                            ->where($this->getColumnFilterStatement($index)[0], '<>', '');
                    } elseif (strlen($value)) {
                        $query->where(function ($query) use ($index) {
                            $query->whereNull(DB::raw($this->getColumnFilterStatement($index)[0]))
                                ->orWhere(DB::raw($this->getColumnFilterStatement($index)[0]), '');
                        });
                    }
                } elseif ($value == 1) {
                    $query->where(DB::raw($this->getColumnFilterStatement($index)[0]), '>', 0);
                } elseif (strlen($value)) {
                    $query->where(function ($query) use ($index) {
                        $query->whereNull(DB::raw($this->getColumnFilterStatement($index)[0]))
                            ->orWhere(DB::raw($this->getColumnFilterStatement($index)[0]), 0);
                    });
                }
            }
        });

        return $this;
    }

    public function addTextFilters()
    {
        if (! count($this->activeTextFilters)) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->activeTextFilters as $index => $activeTextFilter) {
                $query->where(function ($query) use ($index, $activeTextFilter) {
                    foreach ($activeTextFilter as $value) {
                        if ($this->columnIsRelation($this->freshColumns[$index])) {
                            $this->addAggregateFilter($query, $index, $activeTextFilter);
                        } else {
                            $query->orWhere(function ($query) use ($index, $value) {
                                foreach ($this->getColumnFilterStatement($index) as $column) {
                                    $column = is_array($column) ? $column[0] : $column;
                                    $columnString = is_string($column)
                                        ? $column
                                        : $column->getValue(DB::connection()->getQueryGrammar());
                                    $query->orWhereRaw('LOWER(' . $this->tablePrefix . $columnString . ') like ?', [mb_strtolower("%$value%")]);
                                }
                            });
                        }
                    }
                });
            }
        });

        return $this;
    }

    public function addNumberFilters()
    {
        if (! count($this->activeNumberFilters)) {
            return $this;
        }
        $this->query->where(function ($query) {
            foreach ($this->activeNumberFilters as $index => $filter) {
                if ($this->columnIsAggregateRelation($this->freshColumns[$index])) {
                    $this->addAggregateFilter($query, $index, $filter);
                } else {
                    $this->addScopeNumberFilter($query, $index, [
                        isset($filter['start']) ? $filter['start'] : 0,
                        isset($filter['end']) ? $filter['end'] : 9999999999,
                    ]) ?? $query->when(isset($filter['start']), function ($query) use ($filter, $index) {
                        $query->whereRaw($this->getColumnFilterStatement($index)[0] . ' >= ?', $filter['start']);
                    })->when(isset($filter['end']), function ($query) use ($filter, $index) {
                        if (isset($this->freshColumns[$index]['round']) && $this->freshColumns[$index]['round'] !== null) {
                            $query->whereRaw('ROUND(' . $this->getColumnFilterStatement($index)[0] . ',' . $this->freshColumns[$index]['round'] . ') <= ?', $filter['end']);
                        } else {
                            $query->whereRaw($this->getColumnFilterStatement($index)[0] . ' <= ?', $filter['end']);
                        }
                    });
                }
            }
        });

        return $this;
    }

    public function addDateRangeFilter()
    {
        if (! count($this->activeDateFilters)) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->activeDateFilters as $index => $filter) {
                if (! ((isset($filter['start']) && $filter['start'] != '') || (isset($filter['end']) && $filter['end'] != ''))) {
                    break;
                }
                $query->whereBetween($this->getColumnFilterStatement($index)[0], [
                    isset($filter['start']) && $filter['start'] != '' ? $filter['start'] : config('livewire-datatables.default_time_start', '0000-00-00'),
                    isset($filter['end']) && $filter['end'] != '' ? $filter['end'] : config('livewire-datatables.default_time_end', '9999-12-31'),
                ]);
            }
        });

        return $this;
    }

    public function addDatetimeRangeFilter()
    {
        if (! count($this->activeDatetimeFilters)) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->activeDatetimeFilters as $index => $filter) {
                if (! ((isset($filter['start']) && $filter['start'] != '') || (isset($filter['end']) && $filter['end'] != ''))) {
                    break;
                }
                $query->whereBetween($this->getColumnFilterStatement($index)[0], [
                    isset($filter['start']) && $filter['start'] != '' ? $filter['start'] : config('livewire-datatables.default_time_start', '0000-00-00 00:00'),
                    isset($filter['end']) && $filter['end'] != '' ? $filter['end'] : config('livewire-datatables.default_time_end', '9999-12-31 23:59'),
                ]);
            }
        });

        return $this;
    }

    public function addTimeRangeFilter()
    {
        if (! count($this->activeTimeFilters)) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->activeTimeFilters as $index => $filter) {
                $start = isset($filter['start']) && $filter['start'] != '' ? $filter['start'] : '00:00:00';
                $end = isset($filter['end']) && $filter['end'] != '' ? $filter['end'] : '23:59:59';

                if ($end < $start) {
                    $query->where(function ($subQuery) use ($index, $start, $end) {
                        $subQuery->whereBetween($this->getColumnFilterStatement($index)[0], [$start, '23:59'])
                            ->orWhereBetween($this->getColumnFilterStatement($index)[0], ['00:00', $end]);
                    });
                } else {
                    $query->whereBetween($this->getColumnFilterStatement($index)[0], [$start, $end]);
                }
            }
        });

        return $this;
    }

    /**
     * Set the 'ORDER BY' clause of the SQL query.
     *
     * Do not set a 'ORDER BY' clause if the column to be sorted does not have a name assigned.
     * This could be a 'label' or 'checkbox' column which is not 'sortable' by SQL by design.
     */
    public function addSort()
    {
        if (isset($this->sort) && isset($this->freshColumns[$this->sort]) && $this->freshColumns[$this->sort]['name']) {
            if (isset($this->pinnedRecords) && $this->pinnedRecords) {
                $this->query->orderBy(DB::raw('FIELD(id,' . implode(',', $this->pinnedRecords) . ')'), 'DESC');
            }
            $this->query->orderBy(DB::raw($this->getSortString($this->query->getConnection()->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME))), $this->direction ? 'asc' : 'desc');
        }

        return $this;
    }

    public function getCallbacksProperty()
    {
        return collect($this->freshColumns)->filter->callback->mapWithKeys(function ($column) {
            return [$column['name'] => $column['callback']];
        });
    }

    public function getExportCallbacksProperty()
    {
        return collect($this->freshColumns)->filter->exportCallback->mapWithKeys(function ($column) {
            return [$column['name'] => $column['exportCallback']];
        });
    }

    public function getEditablesProperty()
    {
        return collect($this->freshColumns)->filter(function ($column) {
            return $column['type'] === 'editable';
        })->mapWithKeys(function ($column) {
            return [$column['name'] => true];
        });
    }

    public function mapCallbacks($paginatedCollection, $export = false)
    {
        $paginatedCollection->collect()->map(function ($row, $i) use ($export) {
            foreach ($row as $name => $value) {
                if ($this->search && ! config('livewire-datatables.suppress_search_highlights') && $this->searchableColumns()->firstWhere('name', $name)) {
                    $row->$name = $this->highlight($row->$name, $this->search);
                }
                if ($export && isset($this->export_callbacks[$name])) {
                    $values = Str::contains($value, static::SEPARATOR) ? explode(static::SEPARATOR, $value) : [$value, $row];
                    $row->$name = $this->export_callbacks[$name](...$values);
                } elseif (isset($this->editables[$name])) {
                    $row->$name = view('datatables::editable', [
                        'value' => $value,
                        'key' => $this->builder()->getModel()->getQualifiedKeyName(),
                        'column' => Str::after($name, '.'),
                        'rowId' => $row->{$name . '_edit_id'},
                    ]);
                } elseif (isset($this->callbacks[$name]) && is_string($this->callbacks[$name])) {
                    $row->$name = $this->{$this->callbacks[$name]}($value, $row);
                } elseif (Str::startsWith($name, 'callback_')) {
                    $row->$name = $this->callbacks[$name](...explode(static::SEPARATOR, $value));
                } elseif (isset($this->callbacks[$name]) && is_callable($this->callbacks[$name])) {
                    $row->$name = $this->callbacks[$name]($value, $row);
                }
            }

            return $row;
        });

        return $paginatedCollection;
    }

    public function getDisplayValue($index, $value)
    {
        return is_array($this->freshColumns[$index]['filterable']) && is_numeric($value)
            ? collect($this->freshColumns[$index]['filterable'])->firstWhere('id', '=', $value)['name'] ?? $value
            : $value;
    }

    /*  This can be called to apply highlighting of the search term to some string.
     *  Motivation: Call this from your Column::Callback to apply highlight to a chosen section of the result.
     */
    public function highlightStringWithCurrentSearchTerm(string $originalString)
    {
        if (! $this->search) {
            return $originalString;
        } else {
            return static::highlightString($originalString, $this->search);
        }
    }

    /* Utility function for applying highlighting to given string */
    public static function highlightString(string $originalString, string $searchingForThisSubstring)
    {
        $searchStringNicelyHighlightedWithHtml = view(
            'datatables::highlight',
            ['slot' => $searchingForThisSubstring]
        )->render();
        $stringWithHighlightedSubstring = str_ireplace(
            $searchingForThisSubstring,
            $searchStringNicelyHighlightedWithHtml,
            $originalString
        );

        return $stringWithHighlightedSubstring;
    }

    public function isRtl($value)
    {
        $rtlChar = '/[\x{0590}-\x{083F}]|[\x{08A0}-\x{08FF}]|[\x{FB1D}-\x{FDFF}]|[\x{FE70}-\x{FEFF}]/u';

        return preg_match($rtlChar, $value) != 0;
    }

    public function highlight($value, $string)
    {
        if ($this->isRtl($value)) {
            $output = $string;
        }
        $output = substr($value, stripos($value, $string), strlen($string));

        if ($value instanceof View) {
            return $value->with(['value' => str_ireplace($string, (string) view('datatables::highlight', ['slot' => $output]), $value->gatherData()['value'] ?? $value->gatherData()['slot'])]);
        }

        return str_ireplace($string, (string) view('datatables::highlight', ['slot' => $output]), $value);
    }

    #[Layout('datatables::layouts.app')]
    public function render()
    {
        $this->dispatch('refreshDynamic');

        if ($this->persistPerPage) {
            session()->put([$this->sessionStorageKey() . '_perpage' => $this->perPage]);
        }

        return view('datatables::datatable');
    }

    public function export(string $filename = 'DatatableExport.xlsx')
    {
        $this->forgetComputed();

        $export = new DatatableExport($this->getExportResultsSet());
        $export->setFilename($filename);

        return $export->download();
    }

    public function getExportResultsSet()
    {
        return $this->mapCallbacks(
            $this->getQuery()->when(count($this->selected), function ($query) {
                return $query->havingRaw('checkbox_attribute IN (' . implode(',', $this->selected) . ')');
            })->get(),
            true
        )->map(function ($item) {
            return collect($this->columns())->reject(function ($value, $key) {
                return $value->preventExport == true || $value->hidden == true;
            })->mapWithKeys(function ($value, $key) use ($item) {
                return [$value->label ?? $value->name => $item->{$value->name}];
            })->all();
        });
    }

    public function getQuery($export = false)
    {
        $this->buildDatabaseQuery($export);

        return $this->query->toBase();
    }

    public function checkboxQuery()
    {
        return $this->query->reorder()->get()->map(function ($row) {
            return (string) $row->checkbox_attribute;
        });
    }

    public function toggleSelectAll()
    {
        $visible_checkboxes = $this->getQuery()->get()->pluck('checkbox_attribute')->toArray();
        $visible_checkboxes = array_map('strval', $visible_checkboxes);
        if ($this->searchOrFilterActive()) {
            if (count($this->visibleSelected) === count($visible_checkboxes)) {
                $this->selected = array_values(array_diff($this->selected, $visible_checkboxes));
                $this->visibleSelected = [];
            } else {
                $this->selected = array_unique(array_merge($this->selected, $visible_checkboxes));
                sort($this->selected);
                $this->visibleSelected = $visible_checkboxes;
            }
        } else {
            if (count($this->selected) === $this->getQuery()->getCountForPagination()) {
                $this->selected = [];
            } else {
                $this->selected = $this->checkboxQuery()->values()->toArray();
            }
            $this->visibleSelected = $this->selected;
        }

        $this->forgetComputed();
    }

    public function updatedSelected()
    {
        if ($this->searchOrFilterActive()) {
            $this->setVisibleSelected();
        } else {
            $this->visibleSelected = $this->selected;
        }
    }

    public function rowIsSelected($row)
    {
        return isset($row->checkbox_attribute) && in_array($row->checkbox_attribute, $this->selected);
    }

    #[On('saveQuery')]
    public function saveQuery($name, $rules)
    {
        // Override this method with your own method for saving
    }

    #[On('deleteQuery')]
    public function deleteQuery($id)
    {
        // Override this method with your own method for deleting
    }

    public function getSavedQueries()
    {
        // Override this method with your own method for getting saved queries
    }

    public function buildActions()
    {
        // Override this method with your own method for creating mass actions
    }

    public function rowClasses($row, $loop)
    {
        // Override this method with your own method for adding classes to a row
        if ($this->rowIsSelected($row)) {
            return config('livewire-datatables.default_classes.row.selected', 'divide-x divide-gray-100 text-sm text-gray-900 bg-yellow-100');
        } else {
            if ($loop->even) {
                return config('livewire-datatables.default_classes.row.even', 'divide-x divide-gray-100 text-sm text-gray-900 bg-gray-100');
            } else {
                return config('livewire-datatables.default_classes.row.odd', 'divide-x divide-gray-100 text-sm text-gray-900 bg-gray-50');
            }
        }
    }

    public function cellClasses($row, $column)
    {
        // Override this method with your own method for adding classes to a cell
        return config('livewire-datatables.default_classes.cell', 'text-sm text-gray-900');
    }

    public function getMassActions()
    {
        return collect($this->massActions)->map(function ($action) {
            return collect($action)->only(['group', 'value', 'label'])->toArray();
        })->toArray();
    }

    public function getMassActionsProperty()
    {
        $actions = collect($this->buildActions())->flatten();

        $duplicates = $actions->pluck('value')->duplicates();

        if ($duplicates->count()) {
            throw new Exception('Duplicate Mass Action(s): ' . implode(', ', $duplicates->toArray()));
        }

        return $actions->toArray();
    }

    public function getMassActionsOptionsProperty()
    {
        return collect($this->actions)->groupBy(function ($item) {
            return $item['group'];
        }, true);
    }

    public function massActionOptionHandler()
    {
        if (! $this->massActionOption) {
            return;
        }

        $option = $this->massActionOption;

        $action = collect($this->massActions)->filter(function ($item) use ($option) {
            return $item->value === $option;
        })->shift();

        $collection = collect($action);

        if ($collection->get('isExport')) {
            $datatableExport = new DatatableExport($this->getExportResultsSet());

            $datatableExport->setFileName($collection->get('fileName'));

            $datatableExport->setStyles($collection->get('styles'));

            $datatableExport->setColumnWidths($collection->get('widths'));

            return $datatableExport->download();
        }

        if (! count($this->selected)) {
            $this->massActionOption = null;

            return;
        }

        if ($collection->has('callable') && is_callable($action->callable)) {
            $action->callable($option, $this->selected);
        }

        $this->massActionOption = null;
    }

    private function searchOrFilterActive()
    {
        return ! empty($this->search) || $this->getActiveFiltersProperty();
    }

    private function setVisibleSelected()
    {
        $this->visibleSelected = array_intersect($this->getQuery()->get()->pluck('checkbox_attribute')->toArray(), $this->selected);
        $this->visibleSelected = array_map('strval', $this->visibleSelected);
    }
}
