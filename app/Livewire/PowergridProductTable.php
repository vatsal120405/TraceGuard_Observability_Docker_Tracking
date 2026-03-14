<?php

namespace App\Livewire;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\PowerGridFields;

final class PowergridProductTable extends PowerGridComponent
{
    public string $tableName = 'powergridProductTable';

    public function setUp(): array
    {
        $this->showCheckBox();

        return [
            PowerGrid::header()
                ->showSearchInput(),
            PowerGrid::footer()
                ->showPerPage()
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return Product::query();
    }

    public function relationSearch(): array
    {
        return [];
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('category_name', fn (Product $model) => $model->category->name)
            ->add('name')
            ->add('description')
            ->add('price', fn (Product $model) => '$'.number_format($model->price, 2))
            ->add('stock')
            ->add('created_at_formatted', fn (Product $model) => Carbon::parse($model->created_at)->format('d/m/Y H:i:s'));
    }

    public function columns(): array
    {
        return [
            Column::make('Id', 'id'),
            Column::make('Category', 'category_name'),
            Column::make('Name', 'name')
                ->sortable()
                ->searchable(),

            Column::make('Description', 'description')
                ->sortable()
                ->searchable(),

            Column::make('Price', 'price')
                ->sortable()
                ->searchable(),

            Column::make('Stock', 'stock'),
            Column::make('Created at', 'created_at_formatted', 'created_at')
                ->sortable(),

            Column::action('Action'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::datetimepicker('created_at'),
        ];
    }

    public function actions(Product $row): array
    {
        return [
            Button::add('view')
                ->slot('View')
                ->class('bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded shadow-lg transition duration-150 ease-in-out')
                ->route('products.show', ['product' => $row->id]),

            Button::add('edit')
                ->slot('Edit')
                ->class('bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded shadow-lg transition duration-150 ease-in-out')
                ->dispatch('edit-product', ['rowId' => $row->id]),

            Button::add('delete')
                ->slot('Delete')
                ->class('bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded shadow-lg transition duration-150 ease-in-out')
                ->dispatch('delete-product', ['rowId' => $row->id]),
        ];
    }
}
