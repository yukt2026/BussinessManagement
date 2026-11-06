<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'organization_name' => 'Test Org',
            'business_type' => 'business',
            'business_phone' => '9876543210',
            'country' => 'India',
            'state' => 'Delhi',
            'pincode' => '110001',
            'address' => '123 Main St',
            'name' => 'Test User',
            'admin_phone' => '9876543210',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_restaurant_free_trial_registration_grants_restaurant_module(): void
    {
        $response = $this->post('/register', [
            'organization_name' => 'Royal Spice Cafe',
            'business_type' => 'restaurant',
            'business_phone' => '9876543210',
            'country' => 'India',
            'state' => 'Delhi',
            'pincode' => '110001',
            'address' => '456 Food Street',
            'name' => 'Restro Owner',
            'admin_phone' => '9876543210',
            'email' => 'restro@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'plan' => 'trial',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = \App\Models\User::where('email', 'restro@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('restaurant', $user->organization->business_type);

        $hasRestaurant = \App\Services\SubscriptionService::hasFeature($user->organization_id, 'module_restaurant');
        $this->assertTrue($hasRestaurant);

        $hasRetail = \App\Services\SubscriptionService::hasFeature($user->organization_id, 'module_retail');
        $this->assertFalse($hasRetail);

        $menuResponse = $this->actingAs($user)->get('/organization/menu');
        $menuResponse->assertStatus(200);
    }
}
