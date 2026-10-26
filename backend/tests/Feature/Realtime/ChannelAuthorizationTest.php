<?php

namespace Tests\Feature\Realtime;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Signing a channel auth response needs a real (Pusher-protocol) driver; no network is used.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // Channel rules were registered on the boot-time (null) driver; register them on this one.
        require base_path('routes/channels.php');
    }

    private function authorize(?User $user, string $channel)
    {
        if ($user) {
            $this->actingAs($user);
        }

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    public function test_a_user_may_join_their_own_private_channel(): void
    {
        $user = User::factory()->create();

        $this->authorize($user, "private-user.{$user->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_user_may_not_join_someone_elses_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->authorize($user, "private-user.{$other->id}")->assertForbidden();
    }

    public function test_admins_may_join_the_live_monitor_channel(): void
    {
        $this->authorize(User::factory()->admin()->create(), 'private-admin.monitor')
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_bidders_may_not_join_the_live_monitor_channel(): void
    {
        $this->authorize(User::factory()->create(), 'private-admin.monitor')->assertForbidden();
    }

    public function test_guests_cannot_authorize_private_channels(): void
    {
        $user = User::factory()->create();

        $this->authorize(null, "private-user.{$user->id}")->assertUnauthorized();
    }
}
