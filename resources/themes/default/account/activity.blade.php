@extends('theme::layouts.app')

@section('title', __('messages.activity.title'))

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('messages.activity.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">{{ __('messages.activity.subtitle') }}</p>
        </div>

        <div class="overflow-hidden rounded border bg-white">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('messages.activity.time') }}</th>
                        <th class="px-4 py-3">{{ __('messages.activity.action') }}</th>
                        <th class="px-4 py-3">{{ __('messages.activity.description') }}</th>
                        <th class="px-4 py-3">{{ __('messages.activity.ip') }}</th>
                        <th class="px-4 py-3">{{ __('messages.activity.meta') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($activities as $activity)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600">{{ $activity->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-zinc-900">{{ $activity->action }}</td>
                            <td class="px-4 py-3 text-zinc-700">{{ $activity->description }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600">{{ $activity->ip ?: '-' }}</td>
                            <td class="px-4 py-3">
                                @if ($activity->meta)
                                    <code class="block max-w-md whitespace-pre-wrap break-words rounded bg-zinc-50 px-2 py-1 text-xs text-zinc-600">{{ json_encode($activity->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-8 text-center text-zinc-500" colspan="5">{{ __('messages.activity.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $activities->links() }}
    </div>
@endsection
