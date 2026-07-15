<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\ArchivePublicLink;

class ArchivePublicLinkService
{
    public function __construct(
        private readonly ArchiveAuditLogger $auditLogger,
    ) {}

    public function activeFor(Model $item): ?ArchivePublicLink
    {
        return ArchivePublicLink::query()
            ->active()
            ->where('linkable_type', $item->getMorphClass())
            ->where('linkable_id', $item->getKey())
            ->first();
    }

    public function create(Model $item, ?Request $request = null, bool $allowPreview = true, bool $allowDownload = false): ArchivePublicLink
    {
        /** @var ArchivePublicLink $link */
        $link = DB::transaction(function () use ($item, $allowPreview, $allowDownload): ArchivePublicLink {
            $existing = ArchivePublicLink::query()
                ->active()
                ->where('linkable_type', $item->getMorphClass())
                ->where('linkable_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ArchivePublicLink) {
                $existing->forceFill([
                    'allow_preview' => $allowPreview,
                    'allow_download' => $allowDownload,
                ])->save();

                return $existing->refresh();
            }

            $token = Str::random(80);

            return ArchivePublicLink::query()->create([
                'token_hash' => $this->tokenHash($token),
                'token' => $token,
                'linkable_type' => $item->getMorphClass(),
                'linkable_id' => $item->getKey(),
                'linkable_doc_num' => (string) data_get($item, 'doc_num'),
                'item_type' => $this->itemType($item),
                'allow_preview' => $allowPreview,
                'allow_download' => $allowDownload,
                'created_by' => auth()->id(),
            ]);
        });

        $this->auditLogger->publicLinkCreated($link, $request);

        return $link;
    }

    public function revoke(Model $item, ?Request $request = null): ?ArchivePublicLink
    {
        $link = DB::transaction(function () use ($item): ?ArchivePublicLink {
            $existing = ArchivePublicLink::query()
                ->active()
                ->where('linkable_type', $item->getMorphClass())
                ->where('linkable_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof ArchivePublicLink) {
                return null;
            }

            $existing->forceFill([
                'revoked_at' => now(),
                'revoked_by' => auth()->id(),
            ])->save();

            return $existing->refresh();
        });

        if ($link instanceof ArchivePublicLink) {
            $this->auditLogger->publicLinkRevoked($link, $request);
        }

        return $link;
    }

    public function resolve(string $token): ?ArchivePublicLink
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $link = ArchivePublicLink::query()
            ->with('linkable')
            ->active()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('token_hash', $this->tokenHash($token))
            ->first();

        if (! $link instanceof ArchivePublicLink || ! hash_equals((string) $link->token, $token)) {
            return null;
        }

        return $link->linkable instanceof Model ? $link : null;
    }

    public function publicUrl(ArchivePublicLink $link): string
    {
        return match ($link->item_type) {
            'folder' => route('public.archive.folders.show', $link->token),
            default => route('public.archive.files.show', $link->token),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?ArchivePublicLink $link): array
    {
        if (! $link instanceof ArchivePublicLink) {
            return [
                'exists' => false,
                'url' => null,
                'allow_preview' => true,
                'allow_download' => false,
                'revoked' => false,
            ];
        }

        return [
            'exists' => $link->revoked_at === null,
            'url' => $this->publicUrl($link),
            'item_type' => $link->item_type,
            'item_doc_num' => $link->linkable_doc_num,
            'allow_preview' => $link->allow_preview,
            'allow_download' => $link->allow_download,
            'expires_at' => $link->expires_at?->toISOString(),
            'revoked' => $link->revoked_at !== null,
        ];
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function itemType(Model $item): string
    {
        return $item instanceof ArchiveFolder ? 'folder' : 'file';
    }
}
