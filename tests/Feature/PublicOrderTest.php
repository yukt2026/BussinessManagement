<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\Organization;
use App\Models\Location;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;

class PublicOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_order_from_public_menu()
    {
        $org = Organization::create([
            'name' => 'Burger Joint',
            'cgst_percent' => 2.5,
            'sgst_percent' => 2.5,
        ]);
        $loc = Location::create(['organization_id' => $org->id, 'name' => 'Downtown', 'is_active' => true]);
        
        $cat = MenuCategory::create(['organization_id' => $org->id, 'location_id' => $loc->id, 'name' => 'Burgers']);
        $item = MenuItem::create([
            'menu_category_id' => $cat->id,
            'name' => 'Cheeseburger',
            'price' => 10.00,
            'is_available' => true
        ]);

        $table = RestaurantTable::create([
            'organization_id' => $org->id,
            'location_id' => $loc->id,
            'name' => 'Table 5'
        ]);

        // 1. Visit QR code
        $response = $this->get(route('public.menu.table', $table->public_token));
        $response->assertRedirect(route('public.menu', [$table->organization_id, $table->location_id]));
        $response->assertSessionHas('restaurant_table_id', $table->id);

        // 2. Add to cart
        $response = $this->post(route('public.order.add', [$org->id, $loc->id]), [
            'menu_item_id' => $item->id
        ]);
        $response->assertRedirect();
        
        $this->assertEquals(1, session("cart_{$loc->id}")[$item->id]['quantity']);

        // 3. Checkout (Table session is active, so order_type is forced to Dine-in)
        $response = $this->post(route('public.order.place', [$org->id, $loc->id]), [
            'customer_name' => 'John Doe',
            'customer_phone' => '9876543210'
        ]);
        
        $response->assertRedirect();

        $this->assertDatabaseHas('restaurant_orders', [
            'organization_id' => $org->id,
            'customer_name' => 'John Doe',
            'restaurant_table_id' => $table->id,
            'order_type' => 'Dine-in',
            'total' => 10.50 // 10 + 5% tax
        ]);

        $order = RestaurantOrder::first();
        $this->assertDatabaseHas('restaurant_order_items', [
            'restaurant_order_id' => $order->id,
            'menu_item_id' => $item->id,
            'quantity' => 1,
            'total' => 10.00
        ]);

        // 4. Tracking page
        $response = $this->get(route('public.order.track', [$org->id, $loc->id, $order->order_number]));
        $response->assertStatus(200);
        $response->assertSee('John Doe');
    }

    public function test_order_with_zero_gst_does_not_add_tax()
    {
        $org = Organization::create([
            'name' => 'Zero Tax Cafe',
            'cgst_percent' => 0.00,
            'sgst_percent' => 0.00,
        ]);
        $loc = Location::create(['organization_id' => $org->id, 'name' => 'Cafe Outlet', 'is_active' => true]);
        
        $cat = MenuCategory::create(['organization_id' => $org->id, 'location_id' => $loc->id, 'name' => 'Beverages']);
        $item = MenuItem::create([
            'menu_category_id' => $cat->id,
            'name' => 'Coffee',
            'price' => 222.00,
            'is_available' => true
        ]);

        $this->post(route('public.order.add', [$org->id, $loc->id]), [
            'menu_item_id' => $item->id
        ]);

        // Cart should show exactly 222.00 without any tax added
        $cartResponse = $this->get(route('public.order.cart', [$org->id, $loc->id]));
        $cartResponse->assertStatus(200);
        $cartResponse->assertSee('222.00');
        $cartResponse->assertDontSee('Central GST (2.5%)');

        // Place order
        $response = $this->post(route('public.order.place', [$org->id, $loc->id]), [
            'customer_name' => 'Zero Tax Customer',
            'customer_phone' => '9999999999',
            'order_type' => 'Takeaway'
        ]);
        $response->assertRedirect();

        $this->assertDatabaseHas('restaurant_orders', [
            'organization_id' => $org->id,
            'customer_name' => 'Zero Tax Customer',
            'subtotal' => 222.00,
            'tax' => 0.00,
            'total' => 222.00,
        ]);
    }

    public function test_customer_can_order_multiple_variants_at_once()
    {
        $org = Organization::create([
            'name' => 'Pizza Palace',
            'cgst_percent' => 0.00,
            'sgst_percent' => 0.00,
        ]);
        $loc = Location::create(['organization_id' => $org->id, 'name' => 'Main', 'is_active' => true]);
        $cat = MenuCategory::create(['organization_id' => $org->id, 'location_id' => $loc->id, 'name' => 'Pizza']);
        $item = MenuItem::create([
            'menu_category_id' => $cat->id,
            'name' => 'Margherita Pizza',
            'price' => 199.00,
            'has_variants' => true,
            'is_available' => true
        ]);
        $vSmall = $item->variants()->create(['name' => 'Small', 'price' => 150.00, 'sort_order' => 1]);
        $vMedium = $item->variants()->create(['name' => 'Medium', 'price' => 250.00, 'sort_order' => 2]);
        $vLarge = $item->variants()->create(['name' => 'Large', 'price' => 350.00, 'sort_order' => 3]);

        // Submit multiple variations at once (2 Small and 1 Large)
        $this->post(route('public.order.add', [$org->id, $loc->id]), [
            'menu_item_id' => $item->id,
            'variants' => [
                $vSmall->id => 2,
                $vMedium->id => 0,
                $vLarge->id => 1,
            ]
        ]);

        $cartResponse = $this->get(route('public.order.cart', [$org->id, $loc->id]));
        $cartResponse->assertStatus(200);
        $cartResponse->assertSee('Margherita Pizza (Small)');
        $cartResponse->assertSee('Margherita Pizza (Large)');

        // Place order
        $response = $this->post(route('public.order.place', [$org->id, $loc->id]), [
            'customer_name' => 'Multi Variant Customer',
            'customer_phone' => '9876543210',
            'order_type' => 'Takeaway'
        ]);
        $response->assertRedirect();

        // 2 * 150 + 1 * 350 = 300 + 350 = 650
        $this->assertDatabaseHas('restaurant_orders', [
            'organization_id' => $org->id,
            'customer_name' => 'Multi Variant Customer',
            'subtotal' => 650.00,
            'total' => 650.00,
        ]);
    }
}
