<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Google OAuth2 + Drive v3 client (no SDK): connect an account, then
 * upload / list / download backup files. Uses the `drive.file` scope, so the app
 * only ever sees files it created itself.
 */
class GoogleDriveService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const SCOPES = 'https://www.googleapis.com/auth/drive.file openid email';

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => route('tenant.google.callback'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function connect(Tenant $tenant, string $code): void
    {
        $token = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => route('tenant.google.callback'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $email = Http::withToken($token['access_token'])
            ->get('https://openidconnect.googleapis.com/v1/userinfo')
            ->json('email');

        $tenant->update([
            'google_email' => $email,
            'google_token' => [...$token, 'expires_at' => now()->addSeconds($token['expires_in'] - 60)->timestamp],
        ]);
    }

    public function disconnect(Tenant $tenant): void
    {
        if ($token = $tenant->google_token['access_token'] ?? null) {
            Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        }

        $tenant->update(['google_email' => null, 'google_token' => null]);
    }

    public function upload(Tenant $tenant, string $fileName, string $contents): string
    {
        $boundary = 'dd'.bin2hex(random_bytes(8));
        $metadata = json_encode(['name' => $fileName, 'parents' => [$this->folderId($tenant)]]);

        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n{$contents}\r\n--{$boundary}--";

        return $this->client($tenant)
            ->withBody($body, 'multipart/related; boundary='.$boundary)
            ->post(self::UPLOAD.'?uploadType=multipart&fields=id')
            ->throw()
            ->json('id');
    }

    /**
     * @return array<int, array{id: string, name: string, size: int, createdTime: string}>
     */
    public function list(Tenant $tenant): array
    {
        return $this->client($tenant)->get(self::API.'/files', [
            'q' => sprintf("'%s' in parents and trashed = false", $this->folderId($tenant)),
            'orderBy' => 'createdTime desc',
            'pageSize' => 20,
            'fields' => 'files(id,name,size,createdTime)',
        ])->throw()->json('files', []);
    }

    public function download(Tenant $tenant, string $fileId): string
    {
        return $this->client($tenant)->get(self::API.'/files/'.$fileId, ['alt' => 'media'])->throw()->body();
    }

    private function folderId(Tenant $tenant): string
    {
        $cached = $tenant->setting('google_folder_id');
        $client = $this->client($tenant);

        if ($cached && $client->get(self::API.'/files/'.$cached, ['fields' => 'id,trashed'])->json('trashed') === false) {
            return $cached;
        }

        $id = $client->post(self::API.'/files?fields=id', [
            'name' => config('app.name').' Backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ])->throw()->json('id');

        $tenant->update(['settings' => [...($tenant->settings ?? []), 'google_folder_id' => $id]]);

        return $id;
    }

    private function client(Tenant $tenant): PendingRequest
    {
        return Http::withToken($this->accessToken($tenant))->acceptJson()->timeout(60);
    }

    private function accessToken(Tenant $tenant): string
    {
        $token = $tenant->google_token;

        if (! $token) {
            throw new RuntimeException('لم يتم ربط حساب Google Drive بعد.');
        }

        if (($token['expires_at'] ?? 0) > now()->timestamp) {
            return $token['access_token'];
        }

        $fresh = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $token['refresh_token'] ?? '',
            'grant_type' => 'refresh_token',
        ]);

        if ($fresh->failed()) {
            $tenant->update(['google_token' => null, 'google_email' => null]);

            throw new RuntimeException('انتهت صلاحية الربط مع Google، يرجى تسجيل الدخول مجدداً.');
        }

        $tenant->update(['google_token' => [
            ...$token,
            ...$fresh->json(),
            'expires_at' => now()->addSeconds($fresh->json('expires_in') - 60)->timestamp,
        ]]);

        return $tenant->google_token['access_token'];
    }
}
