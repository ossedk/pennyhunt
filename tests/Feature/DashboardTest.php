<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('radar'));
        $response->assertRedirect(route('login'));
    }

    public function test_legacy_dashboard_route_redirects_to_radar()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertRedirect('/radar');
    }

    public function test_authenticated_users_can_visit_all_main_pages()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['radar', 'feed', 'signals', 'backtests', 'watchlists', 'sources'] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
    }
}
