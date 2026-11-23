<?php

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Services\Sending\EmailContentRenderer;
use App\Services\Sending\EmailTracker;
use App\Services\Sending\RenderedEmail;
use Illuminate\Support\Facades\URL;

it('adds an open pixel and tracked links to campaign email', function () {
    $contact = Contact::factory()->consented()->create();
    $campaign = Campaign::factory()->create([
        'track_opens' => true,
        'track_clicks' => true,
        'body' => '<p>See <a href="https://acme.test/pricing?a=1&amp;b=2">pricing</a>.</p>',
    ]);
    $message = EmailMessage::factory()->for($campaign)->for($contact)->create(['to_email' => $contact->email]);

    $html = app(EmailContentRenderer::class)->render($message)->html;

    expect($html)
        ->toContain(e(URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => 'https://acme.test/pricing?a=1&b=2'])))
        ->toContain('/t/o/'.$message->uuid)
        ->not->toContain('href="https://acme.test/pricing');
});

it('never tracks the unsubscribe link', function () {
    $campaign = Campaign::factory()->create(['track_clicks' => true]);
    $message = EmailMessage::factory()->for($campaign)->create();
    $unsubscribeUrl = $message->unsubscribeUrl();
    $rendered = new RenderedEmail('Hi', '<a href="'.e($unsubscribeUrl).'">Unsubscribe</a>', 'Hi', $unsubscribeUrl);

    expect(app(EmailTracker::class)->instrument($rendered, $message, $campaign)->html)->toBe($rendered->html);
});

it('leaves email untouched when tracking is off', function () {
    $campaign = Campaign::factory()->create(['body' => '<p><a href="https://acme.test">Site</a></p>']);
    $message = EmailMessage::factory()->for($campaign)->create();

    expect(app(EmailContentRenderer::class)->render($message)->html)
        ->toContain('href="https://acme.test"')
        ->not->toContain('/t/o/');
});

it('records a click and sends the reader on, even for another client\'s email', function () {
    $message = asClient(Client::factory()->create(), fn () => EmailMessage::factory()->create());

    $this->get(URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => 'https://acme.test/pricing']))
        ->assertRedirect('https://acme.test/pricing');

    expect($message->events()->sole())
        ->type->toBe(EmailEventType::Clicked)
        ->source->toBe(EventSource::Tracking)
        ->details->toBe(['url' => 'https://acme.test/pricing']);
});

it('refuses a click link whose destination was changed, so it cannot redirect anywhere else', function () {
    $message = EmailMessage::factory()->create();
    $url = URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => 'https://acme.test/pricing']);

    $this->get(str_replace('acme.test', 'evil.test', $url))->assertForbidden();

    expect($message->events()->count())->toBe(0);
});

it('refuses non-web destinations', function () {
    $message = EmailMessage::factory()->create();

    $this->get(URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => 'javascript:alert(1)']))->assertNotFound();
});

it('records an open and returns a transparent image', function () {
    $message = EmailMessage::factory()->create();

    $this->get(URL::signedRoute('track.open', ['message' => $message->uuid]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/gif');

    expect($message->events()->sole()->type)->toBe(EmailEventType::Opened);
});

it('does not count link checks that only ask for the headers', function () {
    $message = EmailMessage::factory()->create();

    $this->call('HEAD', URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => 'https://acme.test']))->assertRedirect();

    expect($message->events()->count())->toBe(0);
});

it('stops recording replayed opens but always returns the image', function () {
    $message = EmailMessage::factory()->create();
    $url = URL::signedRoute('track.open', ['message' => $message->uuid]);

    foreach (range(1, 25) as $request) {
        $this->get($url)->assertOk();
    }

    expect($message->events()->count())->toBe(20);
});
