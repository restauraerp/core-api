<?php

namespace Database\Seeders;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\WebsiteSetting;
use App\Models\SocialLink;
use App\Models\Page;
use App\Models\GoogleReview;

class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        // This seeder runs once per demo tenant, so its copy cannot name a
        // single restaurant: the second demo tenant would come out branded
        // "Bangla Bistro" too, and the storefront - which reads site_name -
        // would show two identical restaurants. The literals below are written
        // for the first demo tenant and rewritten to the current one at the
        // point of insert (see $brand()).
        $tenant = app(TenantContext::class)->get();
        $tenantName = $tenant?->name ?? 'Bangla Bistro';
        $tenantHandle = Str::slug($tenantName, '');

        $brand = fn (mixed $value): mixed => is_string($value)
            ? str_replace(['Bangla Bistro', 'banglabistro'], [$tenantName, $tenantHandle], $value)
            : $value;
        // ── Website Settings (Brand & Contact) ──────────────────────────
        $settings = [
            // Brand Identity
            ['key' => 'site_name',        'value' => 'Bangla Bistro',                 'type' => 'string'],
            ['key' => 'tagline',           'value' => 'Timeless Flavors, Crafted with Passion', 'type' => 'string'],
            ['key' => 'logo_url',          'value' => '',                              'type' => 'string'],
            ['key' => 'favicon_url',       'value' => '',                              'type' => 'string'],
            ['key' => 'cover_image_url',   'value' => 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=1600', 'type' => 'string'],

            // Contact & Location
            ['key' => 'contact_email',     'value' => 'hello@banglabistro.com.bd',     'type' => 'string'],
            ['key' => 'contact_phone',     'value' => '+880 1700-000000',              'type' => 'string'],
            ['key' => 'whatsapp_number',   'value' => '+8801700000000',                'type' => 'string'],
            ['key' => 'address',           'value' => 'Road 27, Block J, Banani, Dhaka 1213, Bangladesh', 'type' => 'string'],
            ['key' => 'google_maps_embed', 'value' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3650.4742789290!2d90.406000!3d23.793800!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMjPCsDQ3JzM3LjciTiA5MMKwMjQnMjEuNiJF!5e0!3m2!1sen!2sbd!4v1234567890', 'type' => 'string'],

            // Opening Hours
            ['key' => 'opening_hours',     'value' => json_encode([
                'Monday'    => '12:00 PM – 10:00 PM',
                'Tuesday'   => '12:00 PM – 10:00 PM',
                'Wednesday' => '12:00 PM – 10:00 PM',
                'Thursday'  => '12:00 PM – 11:00 PM',
                'Friday'    => '12:00 PM – 11:00 PM',
                'Saturday'  => '11:00 AM – 11:00 PM',
                'Sunday'    => '11:00 AM – 09:00 PM',
            ]), 'type' => 'json'],

            // SEO
            ['key' => 'meta_title',        'value' => 'Bangla Bistro – Restaurant & Fine Dining in Dhaka', 'type' => 'string'],
            ['key' => 'meta_description',  'value' => 'A warm table in the heart of Dhaka. Seasonal plates cooked fresh every day, thoughtful service, and an easy place to gather — dine in, reserve a table, or order delivery from Bangla Bistro.', 'type' => 'string'],
            ['key' => 'meta_keywords',     'value' => 'Bangla Bistro, restaurant Dhaka, Banani restaurant, fine dining Dhaka, table reservation Dhaka', 'type' => 'string'],

            // Restaurant Details
            ['key' => 'cuisine_type',      'value' => 'Bangladeshi',                   'type' => 'string'],
            ['key' => 'seating_capacity',  'value' => '80',                            'type' => 'string'],
            ['key' => 'founded_year',      'value' => '2015',                          'type' => 'string'],
            ['key' => 'reservation_enabled', 'value' => 'true',                        'type' => 'boolean'],
            ['key' => 'currency_symbol',   'value' => '৳',                             'type' => 'string'],
            // 45 minutes rather than the default hour, so the demo shows a
            // restaurant that has tuned the window to its own kitchen.
            ['key' => 'kitchen_lead_minutes', 'value' => '45',                          'type' => 'number'],
        ];

        foreach ($settings as $s) {
            WebsiteSetting::updateOrCreate(['key' => $s['key']], [
                'value' => $brand($s['value']),
                'type'  => $s['type'],
            ]);
        }

        // ── Social Links ─────────────────────────────────────────────────
        $socialLinks = [
            ['platform' => 'facebook',   'url' => 'https://facebook.com/banglabistro',    'is_active' => 1],
            ['platform' => 'instagram',  'url' => 'https://instagram.com/banglabistro',   'is_active' => 1],
            ['platform' => 'youtube',    'url' => 'https://youtube.com/@banglabistro',    'is_active' => 1],
            ['platform' => 'tiktok',     'url' => 'https://tiktok.com/@banglabistro',     'is_active' => 1],
            ['platform' => 'whatsapp',   'url' => 'https://wa.me/8801700000000',          'is_active' => 1],
        ];

        // NOT truncate(): that ignores the tenant global scope and would wipe
        // every restaurant's social links, not just this one's.
        SocialLink::query()->delete();
        foreach ($socialLinks as $link) {
            SocialLink::create(array_map($brand, $link));
        }

        // ── CMS Pages ────────────────────────────────────────────────────
        $pages = [
            [
                'slug'             => 'about',
                'title'            => 'About Bangla Bistro',
                'meta_title'       => 'About Us – Bangla Bistro, Dhaka',
                'meta_description' => 'Learn about our story, our kitchen, and the people behind the table at Bangla Bistro in Dhaka.',
                'content'          => '<p>Founded in 2015, <strong>Bangla Bistro</strong> was born from a simple dream — to give Dhaka a table where good food and easy company meet, every day of the week.</p><p>Our kitchen is built around craft: everything is prepped in-house each morning, sauces are cooked slowly, and nothing leaves the pass that we would not happily serve our own family. Every dish reflects that discipline.</p><p>We source carefully — the best of the local markets alongside a handful of trusted imports — so that what reaches your plate is fresh, honest, and worth coming back for.</p>',
            ],
            [
                'slug'             => 'privacy-policy',
                'title'            => 'Privacy Policy',
                'meta_title'       => 'Privacy Policy – Bangla Bistro',
                'meta_description' => 'Read our privacy policy to understand how we handle your personal information.',
                'content'          => '<h2>Privacy Policy</h2><p>Last updated: June 2026</p><p>We respect your privacy and are committed to protecting your personal data. This policy describes how we collect, use, and safeguard your information when you make a reservation or order through our platform.</p><h3>Information We Collect</h3><ul><li>Name, email, and phone number for reservations</li><li>Order history for loyalty and service improvement</li></ul><h3>How We Use It</h3><p>Your data is used solely for reservation confirmations, order tracking, and promotional updates (only with your consent). We never sell your data to third parties.</p>',
            ],
            [
                'slug'             => 'terms',
                'title'            => 'Terms & Conditions',
                'meta_title'       => 'Terms & Conditions – Bangla Bistro',
                'meta_description' => 'Read our terms and conditions for dining, reservations, and delivery.',
                'content'          => '<h2>Terms & Conditions</h2><p>By visiting or making a reservation at Bangla Bistro, you agree to the following terms.</p><h3>Reservations</h3><p>Reservations are held for 15 minutes past the booked time. Cancellations must be made at least 2 hours in advance.</p><h3>Delivery</h3><p>Delivery is available within a 5km radius. Orders under BDT 500 may incur a delivery fee.</p>',
            ],
        ];

        foreach ($pages as $p) {
            Page::updateOrCreate(['slug' => $p['slug']], array_map($brand, $p));
        }

        // ── Google Reviews ────────────────────────────────────────────────
        $reviews = [
            ['author_name' => 'Rafiqul Islam',  'rating' => 5, 'is_displayed' => 1, 'time' => now()->subDays(14)->toDateTimeString(), 'text' => 'Absolutely the best food I have had in Dhaka. The carbonara was creamy and perfectly seasoned. Will come back every week!'],
            ['author_name' => 'Sumaiya Khan',   'rating' => 5, 'is_displayed' => 1, 'time' => now()->subDays(30)->toDateTimeString(), 'text' => 'Magical ambience and outstanding food. The wood-fired pizza is a must-try. The staff was incredibly warm and attentive.'],
            ['author_name' => 'Tanvir Ahmed',   'rating' => 5, 'is_displayed' => 1, 'time' => now()->subDays(21)->toDateTimeString(), 'text' => 'Bangla Bistro is our go-to for date nights. The tiramisu is divine. Highly recommend the tasting menu!'],
            ['author_name' => 'Nazmun Nahar',   'rating' => 4, 'is_displayed' => 1, 'time' => now()->subDays(60)->toDateTimeString(), 'text' => 'Great food and lovely interior. The bruschetta starter was excellent. Slight wait time on a Friday evening but absolutely worth it.'],
            ['author_name' => 'Sabbir Hossain', 'rating' => 5, 'is_displayed' => 1, 'time' => now()->subDays(7)->toDateTimeString(),  'text' => 'Took my parents here for their anniversary dinner. The private dining arrangement was perfect. Chef personally came to say hello!'],
            ['author_name' => 'Farzana Begum',  'rating' => 5, 'is_displayed' => 1, 'time' => now()->subDays(5)->toDateTimeString(),  'text' => 'Best tiramisu I have ever had. Ordered delivery and it arrived hot and well-packaged. Will definitely order again!'],
        ];

        // Scoped delete - see the SocialLink note above.
        GoogleReview::query()->delete();
        foreach ($reviews as $r) {
            GoogleReview::create(array_map($brand, $r));
        }

        $this->command->info('✅ WebsiteSeeder: Seeded ' . count($settings) . ' settings, ' . count($socialLinks) . ' social links, ' . count($pages) . ' pages, ' . count($reviews) . ' reviews.');
    }
}
