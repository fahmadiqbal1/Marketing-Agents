<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Business;
use App\Models\SocialPlatform;
use App\Models\AiModelConfig;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Create demo admin user first (needed for business owner_id)
        $user = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'name'     => 'Admin User',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ]
        );

        // Create demo business
        $business = Business::firstOrCreate(
            ['name' => 'Aviva Marketing'],
            [
                'industry'    => 'Marketing & Advertising',
                'website'     => 'https://avivamarketing.com',
                'brand_voice' => 'Professional yet approachable. We speak clearly, confidently, and with purpose.',
                'owner_id'    => $user->id,
            ]
        );

        // Link user to business
        if (!$user->business_id) {
            $user->business_id = $business->id;
            $user->save();
        }

        // Create demo social platforms
        $platforms = [
            ['key' => 'instagram', 'name' => 'Instagram',   'connected' => true],
            ['key' => 'facebook',  'name' => 'Facebook',    'connected' => true],
            ['key' => 'linkedin',  'name' => 'LinkedIn',    'connected' => true],
            ['key' => 'twitter',   'name' => 'Twitter / X', 'connected' => false],
            ['key' => 'tiktok',    'name' => 'TikTok',      'connected' => false],
        ];

        foreach ($platforms as $p) {
            SocialPlatform::firstOrCreate(
                ['business_id' => $business->id, 'key' => $p['key']],
                ['name' => $p['name'], 'connected' => $p['connected']]
            );
        }

        // Create demo AI model config
        AiModelConfig::firstOrCreate(
            ['business_id' => $business->id, 'provider' => 'openai'],
            [
                'api_key'    => 'sk-demo-key-replace-with-real-key',
                'model_name' => 'gpt-4o',
                'is_default' => true,
                'is_active'  => true,
            ]
        );

        $this->command->info('Demo data seeded! Login: admin@demo.com / password');
    }
}
