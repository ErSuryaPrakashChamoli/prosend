<?php

use App\Filament\Pages\ComposeEmail;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ContactLists\ContactListResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\EmailMessages\EmailMessageResource;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Resources\SenderIdentities\SenderIdentityResource;
use App\Filament\Resources\SendingDomains\SendingDomainResource;
use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WarmupPlans\WarmupPlanResource;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\SendingDomain;
use App\Models\SmtpProvider;
use App\Models\User;
use App\Models\WarmupPlan;

it('redirects guests to the sign-in page', function () {
    $this->get(Dashboard::getUrl())->assertRedirect(route('filament.admin.auth.login'));
});

it('renders every page for an administrator', function (string $url) {
    $this->actingAs(User::factory()->admin()->create());

    $this->get($url)->assertOk();
})->with([
    'dashboard' => fn () => Dashboard::getUrl(),
    'compose' => fn () => ComposeEmail::getUrl(),
    'contacts' => fn () => ContactResource::getUrl(),
    'contact' => fn () => ContactResource::getUrl('view', ['record' => Contact::factory()->create()]),
    'new contact' => fn () => ContactResource::getUrl('create'),
    'lists' => fn () => ContactListResource::getUrl(),
    'suppressions' => fn () => SuppressionResource::getUrl(),
    'campaigns' => fn () => CampaignResource::getUrl(),
    'new campaign' => fn () => CampaignResource::getUrl('create'),
    'campaign' => fn () => CampaignResource::getUrl('view', ['record' => Campaign::factory()->create()]),
    'campaign edit' => fn () => CampaignResource::getUrl('edit', ['record' => Campaign::factory()->create()]),
    'email activity' => fn () => EmailMessageResource::getUrl(),
    'email' => fn () => EmailMessageResource::getUrl('view', ['record' => EmailMessage::factory()->accepted()->create()]),
    'providers' => fn () => SmtpProviderResource::getUrl(),
    'provider' => fn () => SmtpProviderResource::getUrl('view', ['record' => SmtpProvider::factory()->create()]),
    'provider edit' => fn () => SmtpProviderResource::getUrl('edit', ['record' => SmtpProvider::factory()->create()]),
    'sender identities' => fn () => SenderIdentityResource::getUrl(),
    'users' => fn () => UserResource::getUrl(),
    'audit logs' => fn () => AuditLogResource::getUrl(),
    'templates' => fn () => EmailTemplateResource::getUrl(),
    'template' => fn () => EmailTemplateResource::getUrl('view', ['record' => EmailTemplate::factory()->create()]),
    'template edit' => fn () => EmailTemplateResource::getUrl('edit', ['record' => EmailTemplate::factory()->create()]),
    'new template' => fn () => EmailTemplateResource::getUrl('create'),
    'segments' => fn () => SegmentResource::getUrl(),
    'segment' => fn () => SegmentResource::getUrl('view', ['record' => Segment::factory()->create()]),
    'segment edit' => fn () => SegmentResource::getUrl('edit', ['record' => Segment::factory()->create()]),
    'new segment' => fn () => SegmentResource::getUrl('create'),
    'domains' => fn () => SendingDomainResource::getUrl(),
    'domain' => fn () => SendingDomainResource::getUrl('view', ['record' => SendingDomain::factory()->create(['provider_dns_records' => ['dkim_record' => ['type' => 'TXT', 'host_name' => 'mail._domainkey', 'value' => 'k=rsa', 'status' => false]]])]),
    'new domain' => fn () => SendingDomainResource::getUrl('create'),
    'warm-up plans' => fn () => WarmupPlanResource::getUrl(),
    'warm-up plan' => fn () => WarmupPlanResource::getUrl('view', ['record' => WarmupPlan::factory()->active()->create()]),
    'new warm-up plan' => fn () => WarmupPlanResource::getUrl('create'),
    'api keys' => fn () => ApiKeyResource::getUrl(),
]);

it('returns 403 to a viewer opening the compose page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(ComposeEmail::getUrl())->assertForbidden();
});

it('returns 403 to a sender opening infrastructure pages', function (string $url) {
    $this->actingAs(User::factory()->sender()->create());

    $this->get($url)->assertForbidden();
})->with([
    'providers' => fn () => SmtpProviderResource::getUrl(),
    'users' => fn () => UserResource::getUrl(),
    'audit logs' => fn () => AuditLogResource::getUrl(),
    'domains' => fn () => SendingDomainResource::getUrl(),
    'warm-up plans' => fn () => WarmupPlanResource::getUrl(),
]);
