<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\MailConfiguration;
use Modules\Core\Services\RequestMemo;
use Throwable;

class MailConfigurationService
{
    public function __construct(
        private readonly RequestMemo $memo,
    ) {}

    public function active(): ?MailConfiguration
    {
        if (! $this->tableExists()) {
            return null;
        }

        return $this->memo->remember('mail_configuration.active', fn (): ?MailConfiguration => MailConfiguration::query()
            ->where('is_active', true)
            ->latest('id')
            ->first());
    }

    public function hasActiveConfiguration(): bool
    {
        return $this->active() instanceof MailConfiguration;
    }

    public function apply(): void
    {
        $configuration = $this->active();

        if (! $configuration instanceof MailConfiguration) {
            return;
        }

        $mailer = $configuration->mailer ?: 'smtp';

        Config::set('mail.default', $mailer);
        Config::set("mail.mailers.{$mailer}", [
            'transport' => $mailer,
            'scheme' => $configuration->scheme,
            'host' => $configuration->host,
            'port' => $configuration->port,
            'username' => $configuration->username,
            'password' => $configuration->password,
            'timeout' => $configuration->timeout,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
        Config::set('mail.from.address', $configuration->from_address);
        Config::set('mail.from.name', $configuration->from_name);
    }

    protected function tableExists(): bool
    {
        return (bool) $this->memo->remember('schema.table.mail_configurations.exists', function (): bool {
            try {
                return Schema::hasTable('mail_configurations');
            } catch (Throwable) {
                return false;
            }
        });
    }
}
