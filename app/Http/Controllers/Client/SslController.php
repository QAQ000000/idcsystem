<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Host;
use App\Modules\Product\Models\SslCertificate;
use App\Modules\Product\Services\SslService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SslController extends Controller
{
    public function index()
    {
        $client = Auth::guard('client')->user();
        $certificates = SslCertificate::query()
            ->with('host')
            ->where('client_id', $client->id)
            ->latest('id')
            ->paginate(20);

        return view('theme::ssl.index', compact('certificates'));
    }

    public function purchase()
    {
        $client = Auth::guard('client')->user();

        return view('theme::ssl.purchase', [
            'hosts' => Host::query()->where('client_id', $client->id)->orderBy('domain')->get(),
        ]);
    }

    public function store(Request $request, SslService $ssl)
    {
        $client = Auth::guard('client')->user();
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:paid,letsencrypt'],
            'years' => ['nullable', 'integer', 'min:1', 'max:10'],
            'host_id' => ['nullable', 'integer', 'exists:hosts,id'],
        ]);

        $host = !empty($data['host_id']) ? Host::query()->find((int) $data['host_id']) : null;

        try {
            $certificate = $ssl->purchase($client, $data['domain'], $data['type'], (int) ($data['years'] ?? 1), $host);
        } catch (\Throwable $exception) {
            return back()->withInput()->withErrors(['domain' => $exception->getMessage()]);
        }

        return redirect()
            ->route('client.ssl.show', $certificate)
            ->with('status', $certificate->type === 'letsencrypt' ? 'Let’s Encrypt 证书已签发' : 'SSL 证书订单已创建，请完成账单支付');
    }

    public function show(SslCertificate $certificate)
    {
        $this->authorizeCertificate($certificate);
        $certificate->load('host');

        return view('theme::ssl.show', compact('certificate'));
    }

    public function deploy(SslCertificate $certificate, SslService $ssl)
    {
        $this->authorizeCertificate($certificate);
        $deployed = $ssl->deploy($certificate);

        return redirect()->route('client.ssl.show', $certificate)->with(
            $deployed ? 'status' : 'error',
            $deployed ? '证书已部署到关联主机' : '当前主机模块不支持自动部署证书'
        );
    }

    public function download(SslCertificate $certificate): Response
    {
        $this->authorizeCertificate($certificate);
        abort_unless($certificate->certificate, 404);

        $content = implode("\n", array_filter([
            $certificate->certificate,
            $certificate->ca_bundle,
        ]));

        return response($content, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="' . $certificate->domain . '.crt"',
        ]);
    }

    private function authorizeCertificate(SslCertificate $certificate): void
    {
        abort_unless((int) $certificate->client_id === (int) Auth::guard('client')->id(), 403);
    }
}
