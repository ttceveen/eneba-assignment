<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $extra = []): array
    {
        return array_replace(['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.com', 'password' => 'SecurePassword123'], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_register_hashes_password_and_issues_seven_day_token_without_cookies(): void
    {
        $this->freezeTime();
        $response = $this->postJson('/api/register', $this->registration(['status' => 'blocked', 'id' => 900]))
            ->assertCreated()->assertHeaderMissing('Set-Cookie')->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.status', 'active')->assertJsonPath('token_type', 'Bearer');
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('SecurePassword123', $user->password));
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertSame(['id', 'first_name', 'last_name', 'email', 'status', 'created_at', 'updated_at'], array_keys($response->json('data')));
        $record = $user->tokens()->firstOrFail();
        $this->assertSame(now()->addDays(7)->toDateTimeString(), $record->expires_at->toDateTimeString());
        $this->assertNotSame($response->json('token'), $record->token);
        $this->bearer($response->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_duplicate_email_including_case_is_rejected(): void
    {
        User::factory()->create(['email' => 'john@example.com']);
        $this->postJson('/api/register', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/register', $this->registration(['email' => 'JOHN@EXAMPLE.COM']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_validation(): void
    {
        $this->postJson('/api/register', [])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
        $this->postJson('/api/register', $this->registration(['first_name' => str_repeat('a', 101), 'last_name' => str_repeat('b', 101), 'email' => 'bad', 'password' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
        $this->postJson('/api/register', $this->registration(['password' => str_repeat('я', 40)]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', $this->registration(['password' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_and_me_use_bearer_without_a_session(): void
    {
        $user = User::factory()->create(['email' => 'john@example.com', 'password' => 'SecurePassword123']);
        $response = $this->postJson('/api/login', ['email' => 'JOHN@example.com', 'password' => 'SecurePassword123'])->assertOk()->assertHeaderMissing('Set-Cookie');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->bearer($response->json('token'))->getJson('/api/me')->assertOk()->assertHeaderMissing('Set-Cookie')->assertJsonPath('data.email', $user->email);
        $this->assertTrue($user->tokens()->firstOrFail()->expires_at->between(now()->addDays(7)->subSeconds(5), now()->addDays(7)));
    }

    public function test_invalid_credentials_and_login_validation(): void
    {
        User::factory()->create(['email' => 'john@example.com']);
        foreach (['john@example.com', 'missing@example.com'] as $email) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_blocked_user_cannot_login_or_use_an_existing_token(): void
    {
        $user = User::factory()->create(['email' => 'john@example.com', 'password' => 'SecurePassword123']);
        $token = $user->createToken('existing')->plainTextToken;
        $user->forceFill(['status' => 'blocked'])->save();
        $this->postJson('/api/login', ['email' => 'john@example.com', 'password' => 'SecurePassword123'])->assertForbidden();
        $this->bearer($token)->getJson('/api/me')->assertForbidden();
        $this->bearer($token)->postJson('/api/logout')->assertForbidden();
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $this->bearer($current)->postJson('/api/logout')->assertOk()->assertJsonPath('message', 'Logged out.');
        $this->bearer($current)->getJson('/api/me')->assertUnauthorized();
        $this->bearer($other)->getJson('/api/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_guests_invalid_and_expired_tokens_cannot_access_private_routes(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->bearer('invalid')->getJson('/api/me')->assertUnauthorized();
        $user = User::factory()->create(['email' => 'john@example.com', 'password' => 'SecurePassword123']);
        $response = $this->postJson('/api/login', ['email' => 'john@example.com', 'password' => 'SecurePassword123'])->assertOk();
        $token = $response->json('token');
        $this->travel(6)->days();
        $this->bearer($token)->getJson('/api/me')->assertOk();
        $this->travel(2)->days();
        $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_web_session_does_not_authenticate_the_api(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_web_csrf_remains_enabled_while_api_login_does_not_require_csrf(): void
    {
        // Enable the real web middleware path instead of Laravel's unit-test exemption.
        $this->app->instance('env', 'local');
        try {
            Route::middleware('web')->post('/_csrf-probe', fn () => response()->json(['ok' => true]));
            $this->postJson('/_csrf-probe')->assertStatus(419);
            $this->postJson('/api/register', $this->registration())->assertCreated()->assertHeaderMissing('Set-Cookie');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_schema_and_migration_rollback(): void
    {
        $this->assertSame(['id', 'email', 'password', 'created_at', 'updated_at', 'first_name', 'last_name', 'status'], Schema::getColumnListing('users'));
        $migration = require database_path('migrations/2026_11_12_000007_update_users_for_authentication.php');
        $user = User::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
        $migration->down();
        $this->assertSame('John Doe', DB::table('users')->where('id', $user->id)->value('name'));
        $migration->up();
        $this->assertSame('Doe', User::find($user->id)->last_name);
        $this->assertSame('active', User::find($user->id)->status);
    }
}
