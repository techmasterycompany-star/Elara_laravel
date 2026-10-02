<?php
// database/factories/BannerFactory.php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return [
            'title'      => fake()->sentence(3),
            'image_path' => 'banners/test.jpg',
            'link'       => fake()->url(),
            'position'   => fake()->numberBetween(1, 10),
            'is_active'  => true,
        ];
    }
}