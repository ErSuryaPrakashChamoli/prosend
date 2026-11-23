<?php

use App\Enums\Permission;
use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unified inbox. Inbound mailboxes are read over IMAP; replies to ProSend's emails become conversations with the
 * prospect, and delivery failure reports become bounces. The built-in roles get the new inbox permissions.
 */
return new class extends Migration
{
    /**
     * Inbox permissions added in this stage, by built-in role.
     *
     * @var list<string>
     */
    public const NEW_PERMISSIONS = ['inbox.view', 'inbox.view_all', 'inbox.reply', 'inbox.assign'];

    public function up(): void
    {
        Schema::create('inbound_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('type', 20)->default('imap');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption', 20)->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('folder')->default('INBOX');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('last_uid')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('health_status', 20)->default('unknown');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'email']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inbound_mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('status', 20)->default('new');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status', 'last_message_at']);
            $table->index(['client_id', 'contact_id', 'campaign_id']);
            $table->index(['client_id', 'assigned_to']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbound_mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10);
            $table->string('message_id_header')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->string('subject');
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('is_auto_reply')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['inbound_mailbox_id', 'message_id_header'], 'conversation_messages_mailbox_message_id_unique');
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        $this->grantNewPermissions();
    }

    /**
     * Built-in roles receive the inbox permissions their defaults include. Roles changed by hand keep their other
     * permissions; custom roles get nothing new.
     */
    public function grantNewPermissions(): void
    {
        foreach (SystemRole::cases() as $role) {
            $grant = array_values(array_intersect(
                self::NEW_PERMISSIONS,
                array_map(fn (Permission $permission): string => $permission->value, $role->defaultPermissions()),
            ));

            $row = DB::table('roles')->where('key', $role->value)->first();

            if ($row === null || $grant === []) {
                continue;
            }

            $current = json_decode((string) $row->permissions, true) ?: [];

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$current, ...$grant]))),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('inbound_mailboxes');

        foreach (DB::table('roles')->get() as $row) {
            $current = json_decode((string) $row->permissions, true) ?: [];

            DB::table('roles')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values(array_diff($current, self::NEW_PERMISSIONS))),
            ]);
        }
    }
};
