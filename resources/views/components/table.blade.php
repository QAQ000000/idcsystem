@props(['rows', 'columns', 'routePrefix' => null])

<div class="overflow-hidden rounded bg-white shadow-sm">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
            <tr>
                @foreach ($columns as $label => $field)
                    <th class="px-4 py-3 font-medium">{{ $label }}</th>
                @endforeach
                @if ($routePrefix)
                    <th class="px-4 py-3 font-medium">{{ __('messages.common.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $field)
                        <td class="px-4 py-3">{{ data_get($row, $field) }}</td>
                    @endforeach
                    @if ($routePrefix)
                        <td class="px-4 py-3">
                            <a class="text-blue-600" href="{{ route($routePrefix . '.show', $row) }}">{{ __('messages.common.view') }}</a>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-6 text-center text-slate-500" colspan="{{ count($columns) + ($routePrefix ? 1 : 0) }}">{{ __('messages.common.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if (method_exists($rows, 'links'))
    <div class="mt-4">{{ $rows->links() }}</div>
@endif
