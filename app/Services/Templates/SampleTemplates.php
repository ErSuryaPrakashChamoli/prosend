<?php

namespace App\Services\Templates;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\EmailTemplate;

/**
 * Starting points for common emails. Link-based templates read the link from the contact's
 * "action_url" attribute and require it, so they are never sent with a broken button.
 */
class SampleTemplates
{
    private const FIRST_NAME = '<span data-type="mergeTag" data-id="first_name"></span>';

    /**
     * Add any sample template that does not exist yet. Returns how many were added.
     */
    public function install(): int
    {
        $installed = 0;

        foreach ($this->definitions() as $definition) {
            $template = EmailTemplate::query()->firstOrCreate(
                ['name' => $definition['name']],
                [...$definition, 'purpose' => $definition['category']->purpose(), 'status' => TemplateStatus::Active],
            );

            $installed += $template->wasRecentlyCreated ? 1 : 0;
        }

        return $installed;
    }

    /**
     * @return list<array{name: string, category: TemplateCategory, subject: string, body: string, required_variables: list<string>}>
     */
    public function definitions(): array
    {
        $hello = '<p>Hi '.self::FIRST_NAME.',</p>';

        return [
            [
                'name' => 'Welcome',
                'category' => TemplateCategory::Welcome,
                'subject' => 'Welcome aboard, {{ first_name }}',
                'body' => $hello.'<p>Thanks for creating your account. You are all set.</p><p>If you have any questions, just reply to this email and our team will help.</p><p>Welcome aboard!</p>',
                'required_variables' => [],
            ],
            [
                'name' => 'Email verification',
                'category' => TemplateCategory::EmailVerification,
                'subject' => 'Confirm your email address',
                'body' => $hello.'<p>Please confirm that this is your email address so we can keep your account secure.</p><p><a href="{{ action_url }}">Confirm email address</a></p><p>If you did not create an account, you can ignore this email.</p>',
                'required_variables' => ['action_url'],
            ],
            [
                'name' => 'Password reset',
                'category' => TemplateCategory::PasswordReset,
                'subject' => 'Reset your password',
                'body' => $hello.'<p>We received a request to reset the password for your account.</p><p><a href="{{ action_url }}">Choose a new password</a></p><p>If you did not ask for this, ignore this email and your password will stay the same.</p>',
                'required_variables' => ['action_url'],
            ],
            [
                'name' => 'Account notification',
                'category' => TemplateCategory::AccountNotification,
                'subject' => 'An update about your account',
                'body' => $hello.'<p>We are writing to let you know about a change to your account.</p><p>[Describe the change and anything the person needs to do.]</p><p>If you have questions, reply to this email.</p>',
                'required_variables' => [],
            ],
            [
                'name' => 'Order or application update',
                'category' => TemplateCategory::OrderUpdate,
                'subject' => 'Update on {{ order_number }}',
                'body' => $hello.'<p>There is an update on <strong>{{ order_number }}</strong>: its status is now <strong>{{ order_status }}</strong>.</p><p>We will let you know as soon as anything changes.</p>',
                'required_variables' => ['order_number', 'order_status'],
            ],
            [
                'name' => 'Newsletter',
                'category' => TemplateCategory::Newsletter,
                'subject' => 'This month at a glance',
                'body' => $hello.'<p>Here is what is new this month.</p><h2>Headline story</h2><p>[A short summary of your most important news.]</p><h2>Also new</h2><ul><li><p>[Update one]</p></li><li><p>[Update two]</p></li></ul><p>Thanks for reading.</p>',
                'required_variables' => [],
            ],
            [
                'name' => 'General announcement',
                'category' => TemplateCategory::Announcement,
                'subject' => 'Some news we wanted to share',
                'body' => $hello.'<p>We have some news to share with you.</p><p>[Explain the announcement and why it matters to the reader.]</p><p>Thank you for being with us.</p>',
                'required_variables' => [],
            ],
        ];
    }
}
