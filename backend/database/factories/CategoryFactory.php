<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $names = [
            ['en' => 'Electronics', 'ar' => 'إلكترونيات'],
            ['en' => 'Clothing', 'ar' => 'ملابس'],
            ['en' => 'Furniture', 'ar' => 'أثاث'],
            ['en' => 'Sports', 'ar' => 'رياضة'],
            ['en' => 'Books', 'ar' => 'كتب'],
            ['en' => 'Toys', 'ar' => 'ألعاب'],
            ['en' => 'Kitchen', 'ar' => 'مطبخ'],
            ['en' => 'Garden', 'ar' => 'حدائق'],
            ['en' => 'Automotive', 'ar' => 'سيارات'],
            ['en' => 'Jewelry', 'ar' => 'مجوهرات'],
            ['en' => 'Footwear', 'ar' => 'أحذية'],
            ['en' => 'Accessories', 'ar' => 'إكسسوارات'],
            ['en' => 'Health', 'ar' => 'صحة'],
            ['en' => 'Beauty', 'ar' => 'جمال'],
            ['en' => 'Kids', 'ar' => 'أطفال'],
            ['en' => 'Pets', 'ar' => 'حيوانات أليفة'],
            ['en' => 'Tools', 'ar' => 'أدوات'],
            ['en' => 'Music', 'ar' => 'موسيقى'],
            ['en' => 'Art', 'ar' => 'فن'],
            ['en' => 'Travel', 'ar' => 'سفر'],
        ];

        $pair = fake()->unique()->randomElement($names);

        return [
            'name_en' => $pair['en'],
            'name_ar' => $pair['ar'],
        ];
    }
}
