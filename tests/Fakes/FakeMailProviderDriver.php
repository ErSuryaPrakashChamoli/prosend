<?php

namespace Tests\Fakes;

use App\Enums\ProviderType;
use App\Models\EmailMessage;
use App\Models\SmtpProvider;
use App\Services\Providers\MailProviderDriver;
use App\Services\Providers\MailProviderManager;
use App\Services\Providers\ProviderCapabilities;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Records sent messages in memory, or fails like an SMTP server would.
 */
class FakeMailProviderDriver implements MailProviderDriver, TransportInterface
{
    /**
     * @var list<SentMessage>
     */
    public array $sent = [];

    public int $connectionTests = 0;

    private ?Throwable $failure = null;

    public static function install(): self
    {
        $driver = new self;

        foreach (ProviderType::cases() as $type) {
            app(MailProviderManager::class)->extend($type, $driver);
        }

        return $driver;
    }

    public function failWith(Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function mailer(SmtpProvider $provider): MailerContract
    {
        return new Mailer('fake', app('view'), $this, app('events'));
    }

    public function testConnection(SmtpProvider $provider): void
    {
        $this->connectionTests++;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function headersFor(EmailMessage $message): array
    {
        return ['X-Mailin-custom' => $message->uuid];
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(deliveryWebhooks: true);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->sent[] = new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'fake://';
    }
}
