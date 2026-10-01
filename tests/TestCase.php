<?php

namespace Tests;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Admin screens/API require a signed-in admin (session auth). */
    protected function signInAdmin(): User
    {
        $admin = User::query()->where('role', User::ROLE_SUPERADMIN)->first()
            ?? User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $this->actingAs($admin);

        return $admin;
    }

    /** Creates an order from the simple Commandes form fields (mapped onto the Shopify-compatible columns). */
    protected function order(array $data): Order
    {
        return Order::create(Order::attributesFromForm($data));
    }
}
