<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientSegment;
use App\Modules\User\Services\ClientSegmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ClientSegmentController extends Controller
{
    public function index(): View
    {
        $segments = ClientSegment::query()->latest()->paginate(20);

        return view('admin.client-segments.index', compact('segments'));
    }

    public function create(): View
    {
        return view('admin.client-segments.create', [
            'segment' => new ClientSegment(['type' => 'static']),
        ]);
    }

    public function store(Request $request, ClientSegmentService $segments): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $data['rules'] = $data['type'] === 'dynamic' ? $segments->normalizeRules($data['rules'] ?? []) : null;
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['rules' => $exception->getMessage()]);
        }

        $segment = ClientSegment::query()->create($data);
        if ($segment->isDynamic()) {
            $segments->calculate($segment);
        }

        return redirect()->route('admin.client-segments.show', $segment)->with('status', '客户分群已创建');
    }

    public function show(ClientSegment $clientSegment): View
    {
        $clientSegment->loadCount('members');
        $members = $clientSegment->clients()
            ->latest('client_segment_members.added_at')
            ->paginate(20);

        return view('admin.client-segments.show', compact('clientSegment', 'members'));
    }

    public function calculate(ClientSegment $clientSegment, ClientSegmentService $segments): RedirectResponse
    {
        $count = $segments->calculate($clientSegment);

        return redirect()
            ->route('admin.client-segments.show', $clientSegment)
            ->with('status', "客户分群已更新，共 {$count} 个客户");
    }

    public function addMembers(Request $request, ClientSegment $clientSegment, ClientSegmentService $segments): RedirectResponse
    {
        $data = $request->validate([
            'client_ids' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $count = $segments->addToSegment($clientSegment, $this->clientIds((string) $data['client_ids']));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['client_ids' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.client-segments.show', $clientSegment)
            ->with('status', "客户分群成员已更新，共 {$count} 个客户");
    }

    public function removeMember(ClientSegment $clientSegment, Client $client, ClientSegmentService $segments): RedirectResponse
    {
        $segments->removeFromSegment($clientSegment, [$client->id]);

        return redirect()->route('admin.client-segments.show', $clientSegment)->with('status', '客户已移出分群');
    }

    public function destroy(ClientSegment $clientSegment): RedirectResponse
    {
        $clientSegment->delete();

        return redirect()->route('admin.client-segments.index')->with('status', '客户分群已删除');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['static', 'dynamic'])],
            'rules' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function clientIds(string $input): array
    {
        return collect(preg_split('/[\s,]+/', $input) ?: [])
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
