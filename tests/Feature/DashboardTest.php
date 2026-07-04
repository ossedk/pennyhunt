<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_root_redirects_to_the_desk()
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_desk_renders_for_authenticated_users()
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('movers')
            ->has('loudest')
            ->has('hypedPosts')
            ->has('news')
            ->where('brief', null));
    }

    public function test_authenticated_users_can_visit_all_main_pages()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['dashboard', 'radar', 'feed', 'signals', 'backtests', 'watchlists', 'sources'] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
    }
}
